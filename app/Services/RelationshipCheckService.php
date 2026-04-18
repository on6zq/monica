<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Account\Account;
use App\Models\Relationship\Relationship;
use App\Services\Instance\IdHasher;
use App\Services\Contact\Relationship\CreateRelationship;

/**
 * Shared logic for auditing and inferring contact relationships.
 * Used by both artisan commands and the settings UI.
 */
class RelationshipCheckService
{
    /**
     * Find all relationships that are missing their reciprocal counterpart.
     */
    public function getMissingReciprocals(): array
    {
        $missing = [];
        $hasher  = app(IdHasher::class);

        $relationships = Relationship::with(['relationshipType', 'contactIs', 'ofContact'])->get();

        foreach ($relationships as $rel) {
            $type = $rel->relationshipType;

            if (! $type) {
                continue;
            }

            $reverseType = $type->reverseRelationshipType();

            if (! $reverseType) {
                continue;
            }

            $reverseExists = Relationship::where([
                'account_id'           => $rel->account_id,
                'contact_is'           => $rel->of_contact,
                'of_contact'           => $rel->contact_is,
                'relationship_type_id' => $reverseType->id,
            ])->exists();

            if (! $reverseExists) {
                $missing[] = [
                    'rel'           => $rel,
                    'reverseType'   => $reverseType,
                    'from_name'     => optional($rel->contactIs)->name ?? "contact#{$rel->contact_is}",
                    'to_name'       => optional($rel->ofContact)->name ?? "contact#{$rel->of_contact}",
                    'from_hash'     => $rel->contactIs ? $hasher->encodeId($rel->contact_is) : null,
                    'to_hash'       => $rel->ofContact ? $hasher->encodeId($rel->of_contact) : null,
                    'type_name'     => $type->name,
                    'rev_type_name' => $reverseType->name,
                ];
            }
        }

        return $missing;
    }

    /**
     * Create all missing reciprocal relationships.
     *
     * @return int number of reciprocals created
     */
    public function fixMissingReciprocals(): int
    {
        $count = 0;

        foreach ($this->getMissingReciprocals() as $m) {
            Relationship::create([
                'account_id'           => $m['rel']->account_id,
                'contact_is'           => $m['rel']->of_contact,
                'of_contact'           => $m['rel']->contact_is,
                'relationship_type_id' => $m['reverseType']->id,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Detect circular parent-child relationships: A is parent of B AND B is parent of A.
     * This indicates data entered in the wrong direction in Monica.
     */
    public function getCircularRelationships(): array
    {
        $circular = [];
        $seen     = [];

        foreach (Account::all() as $account) {
            $types    = $this->loadTypes($account->id);
            $contacts = $this->loadContactInfo($account->id);

            if (! isset($types['child'])) {
                continue;
            }

            $parentPairs = DB::table('relationships')
                ->where('account_id', $account->id)
                ->where('relationship_type_id', $types['child'])
                ->get(['contact_is', 'of_contact'])
                ->mapWithKeys(fn ($r) => ["{$r->contact_is}-{$r->of_contact}" => true])
                ->all();

            foreach (array_keys($parentPairs) as $pair) {
                [$a, $b] = explode('-', $pair, 2);
                $reverse  = "{$b}-{$a}";
                $key      = min($a, $b).'-'.max($a, $b);

                if (isset($parentPairs[$reverse]) && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $infoA = $contacts[(int) $a] ?? null;
                    $infoB = $contacts[(int) $b] ?? null;

                    $circular[] = [
                        'contact_a_id'   => (int) $a,
                        'contact_b_id'   => (int) $b,
                        'contact_a_name' => $infoA['name'] ?? "contact#{$a}",
                        'contact_b_name' => $infoB['name'] ?? "contact#{$b}",
                        'contact_a_hash' => $infoA['hash'] ?? null,
                        'contact_b_hash' => $infoB['hash'] ?? null,
                    ];
                }
            }
        }

        return $circular;
    }

    /**
     * Infer missing relationships from existing ones.
     * Contacts involved in circular parent relationships are excluded from inference
     * as their parent data is contradictory.
     */
    public function getSuggestedRelationships(): array
    {
        $allSuggestions = [];

        foreach (Account::all() as $account) {
            $types    = $this->loadTypes($account->id);
            $contacts = $this->loadContactInfo($account->id);

            // Contacts with circular parent data produce unreliable inferences — skip them
            $circularIds = $this->getCircularParentIds($account->id, $types);

            $allSuggestions = array_merge(
                $allSuggestions,
                $this->inferSiblings($account->id, $types, $contacts, $circularIds),
                $this->inferGrandparents($account->id, $types, $contacts, $circularIds),
                $this->inferUncles($account->id, $types, $contacts, $circularIds),
            );
        }

        return $allSuggestions;
    }

    /**
     * Create all suggested relationships.
     *
     * @return int number of relationships created
     */
    public function applySuggestions(): int
    {
        $service = new CreateRelationship;
        $count   = 0;

        foreach ($this->getSuggestedRelationships() as $s) {
            $service->execute([
                'account_id'           => $s['account_id'],
                'contact_is'           => $s['from_id'],
                'of_contact'           => $s['to_id'],
                'relationship_type_id' => $s['type_id'],
            ]);
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array<string, int> name => id */
    private function loadTypes(int $accountId): array
    {
        return DB::table('relationship_types')
            ->where('account_id', $accountId)
            ->pluck('id', 'name')
            ->all();
    }

    /**
     * Load contacts as id => ['name' => ..., 'hash' => ...]
     *
     * @return array<int, array{name: string, hash: string}>
     */
    private function loadContactInfo(int $accountId): array
    {
        $hasher = app(IdHasher::class);

        return DB::table('contacts')
            ->where('account_id', $accountId)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($c) => [
                $c->id => [
                    'name' => trim("{$c->first_name} {$c->last_name}"),
                    'hash' => $hasher->encodeId($c->id),
                ],
            ])
            ->all();
    }

    /**
     * Return IDs of contacts on either side of a circular parent-child relationship.
     * These contacts have contradictory parent data and should not be used for inference.
     *
     * @return array<int, true>
     */
    private function getCircularParentIds(int $accountId, array $types): array
    {
        if (! isset($types['child'])) {
            return [];
        }

        $parentPairs = DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['child'])
            ->get(['contact_is', 'of_contact'])
            ->mapWithKeys(fn ($r) => ["{$r->contact_is}-{$r->of_contact}" => true])
            ->all();

        $ids = [];

        foreach (array_keys($parentPairs) as $pair) {
            [$a, $b] = explode('-', $pair, 2);

            if (isset($parentPairs["{$b}-{$a}"])) {
                $ids[(int) $a] = true;
                $ids[(int) $b] = true;
            }
        }

        return $ids;
    }

    /** Rule: A is parent of B, A is parent of C (B≠C) → suggest sibling B↔C */
    private function inferSiblings(int $accountId, array $types, array $contacts, array $circularIds): array
    {
        if (! isset($types['child'], $types['sibling'])) {
            return [];
        }

        $childrenOf = [];
        DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['child'])
            ->get(['contact_is as parent_id', 'of_contact as child_id'])
            ->each(function ($row) use (&$childrenOf) {
                $childrenOf[$row->parent_id][] = $row->child_id;
            });

        $existingSiblings = DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['sibling'])
            ->get(['contact_is', 'of_contact'])
            ->mapWithKeys(fn ($r) => [min($r->contact_is, $r->of_contact).'-'.max($r->contact_is, $r->of_contact) => true])
            ->all();

        $suggestions = [];
        $seen        = [];

        foreach ($childrenOf as $parentId => $children) {
            // Skip parents with contradictory data
            if (isset($circularIds[$parentId])) {
                continue;
            }

            $parentInfo = $contacts[$parentId] ?? null;
            $parentName = $parentInfo['name'] ?? "contact#{$parentId}";
            $count      = count($children);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $b   = $children[$i];
                    $c   = $children[$j];
                    $key = min($b, $c).'-'.max($b, $c);

                    if (isset($seen[$key]) || isset($existingSiblings[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $infoB = $contacts[$b] ?? null;
                    $infoC = $contacts[$c] ?? null;

                    $suggestions[] = [
                        'account_id' => $accountId,
                        'from_id'    => $b,
                        'to_id'      => $c,
                        'from_hash'  => $infoB['hash'] ?? null,
                        'to_hash'    => $infoC['hash'] ?? null,
                        'type_id'    => $types['sibling'],
                        'type_name'  => 'sibling',
                        'from_name'  => $infoB['name'] ?? "contact#{$b}",
                        'to_name'    => $infoC['name'] ?? "contact#{$c}",
                        'reason'     => "both children of {$parentName}",
                    ];
                }
            }
        }

        return $suggestions;
    }

    /** Rule: A is parent of B, B is parent of C → suggest grandparent A→C */
    private function inferGrandparents(int $accountId, array $types, array $contacts, array $circularIds): array
    {
        if (! isset($types['child'], $types['grandparent'])) {
            return [];
        }

        $childrenOf = [];
        DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['child'])
            ->get(['contact_is as parent_id', 'of_contact as child_id'])
            ->each(function ($row) use (&$childrenOf) {
                $childrenOf[$row->parent_id][] = $row->child_id;
            });

        $existingGrandparent = DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['grandparent'])
            ->get(['contact_is', 'of_contact'])
            ->mapWithKeys(fn ($r) => ["{$r->contact_is}-{$r->of_contact}" => true])
            ->all();

        $suggestions = [];
        $seen        = [];

        foreach ($childrenOf as $a => $bList) {
            if (isset($circularIds[$a])) {
                continue;
            }

            foreach ($bList as $b) {
                if (isset($circularIds[$b])) {
                    continue;
                }

                foreach ($childrenOf[$b] ?? [] as $c) {
                    $key = "{$a}-{$c}";

                    if (isset($seen[$key]) || isset($existingGrandparent[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $bName  = $contacts[$b]['name'] ?? "contact#{$b}";
                    $infoA  = $contacts[$a] ?? null;
                    $infoC  = $contacts[$c] ?? null;

                    $suggestions[] = [
                        'account_id' => $accountId,
                        'from_id'    => $a,
                        'to_id'      => $c,
                        'from_hash'  => $infoA['hash'] ?? null,
                        'to_hash'    => $infoC['hash'] ?? null,
                        'type_id'    => $types['grandparent'],
                        'type_name'  => 'grandparent',
                        'from_name'  => $infoA['name'] ?? "contact#{$a}",
                        'to_name'    => $infoC['name'] ?? "contact#{$c}",
                        'reason'     => "parent of {$bName} who is parent of grandchild",
                    ];
                }
            }
        }

        return $suggestions;
    }

    /** Rule: A is sibling of B, B is parent of C → suggest uncle A→C */
    private function inferUncles(int $accountId, array $types, array $contacts, array $circularIds): array
    {
        if (! isset($types['sibling'], $types['child'], $types['uncle'])) {
            return [];
        }

        $siblingsOf = [];
        DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['sibling'])
            ->get(['contact_is', 'of_contact'])
            ->each(function ($row) use (&$siblingsOf) {
                $siblingsOf[$row->contact_is][] = $row->of_contact;
            });

        $childrenOf = [];
        DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['child'])
            ->get(['contact_is as parent_id', 'of_contact as child_id'])
            ->each(function ($row) use (&$childrenOf) {
                $childrenOf[$row->parent_id][] = $row->child_id;
            });

        $existingUncle = DB::table('relationships')
            ->where('account_id', $accountId)
            ->where('relationship_type_id', $types['uncle'])
            ->get(['contact_is', 'of_contact'])
            ->mapWithKeys(fn ($r) => ["{$r->contact_is}-{$r->of_contact}" => true])
            ->all();

        $suggestions = [];
        $seen        = [];

        foreach ($siblingsOf as $a => $bList) {
            foreach ($bList as $b) {
                if (isset($circularIds[$b])) {
                    continue;
                }

                foreach ($childrenOf[$b] ?? [] as $c) {
                    $key = "{$a}-{$c}";

                    if (isset($seen[$key]) || isset($existingUncle[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $bName  = $contacts[$b]['name'] ?? "contact#{$b}";
                    $infoA  = $contacts[$a] ?? null;
                    $infoC  = $contacts[$c] ?? null;

                    $suggestions[] = [
                        'account_id' => $accountId,
                        'from_id'    => $a,
                        'to_id'      => $c,
                        'from_hash'  => $infoA['hash'] ?? null,
                        'to_hash'    => $infoC['hash'] ?? null,
                        'type_id'    => $types['uncle'],
                        'type_name'  => 'uncle',
                        'from_name'  => $infoA['name'] ?? "contact#{$a}",
                        'to_name'    => $infoC['name'] ?? "contact#{$c}",
                        'reason'     => "sibling of {$bName} who is parent of nephew/niece",
                    ];
                }
            }
        }

        return $suggestions;
    }
}
