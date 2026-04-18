<?php

namespace App\Http\Controllers\Settings;

use App\Helpers\AccountHelper;
use App\Http\Controllers\Controller;
use App\Services\RelationshipCheckService;
use App\Services\Contact\Relationship\CreateRelationship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RelationsCheckController extends Controller
{
    /**
     * Display the relations check page.
     */
    public function index()
    {
        $service = new RelationshipCheckService;

        $accountHasLimitations = AccountHelper::hasLimitations(auth()->user()->account);

        return view('settings.relationscheck.index')
            ->withMissingReciprocals($service->getMissingReciprocals())
            ->withCircularRelationships($service->getCircularRelationships())
            ->withSuggestions($service->getSuggestedRelationships())
            ->withAccountHasLimitations($accountHasLimitations);
    }

    /**
     * Create all missing reciprocal relationships.
     */
    public function fixReciprocals(): RedirectResponse
    {
        $count = (new RelationshipCheckService)->fixMissingReciprocals();

        return redirect()->route('settings.relationscheck.index')
            ->with('status', trans_choice('settings.relationscheck_fixed_reciprocals', $count, ['count' => $count]));
    }

    /**
     * Apply all inferred relationship suggestions.
     */
    public function applySuggestions(): RedirectResponse
    {
        $count = (new RelationshipCheckService)->applySuggestions();

        return redirect()->route('settings.relationscheck.index')
            ->with('status', trans_choice('settings.relationscheck_applied_suggestions', $count, ['count' => $count]));
    }

    /**
     * Create a single inferred relationship suggestion.
     */
    public function createSuggestion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id'           => 'required|integer',
            'from_id'              => 'required|integer',
            'to_id'                => 'required|integer',
            'relationship_type_id' => 'required|integer',
        ]);

        (new CreateRelationship)->execute([
            'account_id'           => $data['account_id'],
            'contact_is'           => $data['from_id'],
            'of_contact'           => $data['to_id'],
            'relationship_type_id' => $data['relationship_type_id'],
        ]);

        return redirect()->route('settings.relationscheck.index')
            ->with('status', trans('settings.relationscheck_created_suggestion'));
    }
}
