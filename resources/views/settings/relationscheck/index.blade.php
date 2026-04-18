@extends('layouts.skeleton')

@section('content')

<div class="settings">

  {{-- Breadcrumb --}}
  <div class="breadcrumb">
    <div class="{{ Auth::user()->getFluidLayout() }}">
      <div class="row">
        <div class="col-12">
          <ul class="horizontal">
            <li>
              <a href="{{ route('dashboard.index') }}">{{ trans('app.breadcrumb_dashboard') }}</a>
            </li>
            <li>
              <a href="{{ route('settings.index') }}">{{ trans('app.breadcrumb_settings') }}</a>
            </li>
            <li>
              {{ trans('settings.sidebar_settings_relationscheck') }}
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <div class="{{ Auth::user()->getFluidLayout() }}">
    <div class="row">

      @include('settings._sidebar')

      <div class="col-12 col-sm-9">

        @if (session('status'))
          <div class="alert alert-success mb3">
            {{ session('status') }}
          </div>
        @endif

        {{-- Circular relationships (data errors) --}}
        <div class="br3 ba b--gray-monica bg-white mb4">
          <div class="pa3 bb b--gray-monica">
            <h3>{{ trans('settings.relationscheck_circular_title') }}</h3>
            <p class="mb3 mt1 lh-copy">{{ trans('settings.relationscheck_circular_help') }}</p>
          </div>

          <div class="pa3">
            @if (count($circularRelationships) === 0)
              <p class="tc green">{{ trans('settings.relationscheck_circular_none') }}</p>
            @else
              <ul class="table">
                <li class="table-row">
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_contact_a') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_contact_b') }}</div>
                </li>
                @foreach ($circularRelationships as $item)
                  <li class="table-row">
                    <div class="table-cell">
                      @if ($item['contact_a_hash'])
                        <a href="{{ route('people.show', $item['contact_a_hash']) }}">{{ $item['contact_a_name'] }}</a>
                      @else
                        {{ $item['contact_a_name'] }}
                      @endif
                    </div>
                    <div class="table-cell">
                      @if ($item['contact_b_hash'])
                        <a href="{{ route('people.show', $item['contact_b_hash']) }}">{{ $item['contact_b_name'] }}</a>
                      @else
                        {{ $item['contact_b_name'] }}
                      @endif
                    </div>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </div>

        {{-- Missing reciprocals --}}
        <div class="br3 ba b--gray-monica bg-white mb4">
          <div class="pa3 bb b--gray-monica">
            <h3>{{ trans('settings.relationscheck_reciprocals_title') }}</h3>
            <p class="mb3 mt1 lh-copy">{{ trans('settings.relationscheck_reciprocals_help') }}</p>
          </div>

          <div class="pa3">
            @if (count($missingReciprocals) === 0)
              <p class="tc green">{{ trans('settings.relationscheck_reciprocals_none') }}</p>
            @else
              <ul class="table mb3">
                <li class="table-row">
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_from') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_type') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_to') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_missing') }}</div>
                </li>
                @foreach ($missingReciprocals as $item)
                  <li class="table-row">
                    <div class="table-cell">
                      @if ($item['from_hash'])
                        <a href="{{ route('people.show', $item['from_hash']) }}">{{ $item['from_name'] }}</a>
                      @else
                        {{ $item['from_name'] }}
                      @endif
                    </div>
                    <div class="table-cell">{{ $item['type_name'] }}</div>
                    <div class="table-cell">
                      @if ($item['to_hash'])
                        <a href="{{ route('people.show', $item['to_hash']) }}">{{ $item['to_name'] }}</a>
                      @else
                        {{ $item['to_name'] }}
                      @endif
                    </div>
                    <div class="table-cell">{{ $item['rev_type_name'] }}</div>
                  </li>
                @endforeach
              </ul>

              <form method="POST" action="{{ route('settings.relationscheck.fix-reciprocals') }}">
                @csrf
                <button type="submit" class="btn btn-primary">
                  {{ trans('settings.relationscheck_fix_reciprocals') }}
                </button>
              </form>
            @endif
          </div>
        </div>

        {{-- Suggested relationships --}}
        <div class="br3 ba b--gray-monica bg-white mb4">
          <div class="pa3 bb b--gray-monica">
            <h3>{{ trans('settings.relationscheck_suggestions_title') }}</h3>
            <p class="mb3 mt1 lh-copy">{{ trans('settings.relationscheck_suggestions_help') }}</p>
          </div>

          <div class="pa3">
            @if (count($suggestions) === 0)
              <p class="tc green">{{ trans('settings.relationscheck_suggestions_none') }}</p>
            @else
              <ul class="table mb3">
                <li class="table-row">
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_from') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_suggested_type') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_to') }}</div>
                  <div class="table-cell table-header">{{ trans('settings.relationscheck_col_reason') }}</div>
                  <div class="table-cell table-header"></div>
                </li>
                @foreach ($suggestions as $item)
                  <li class="table-row">
                    <div class="table-cell">
                      @if ($item['from_hash'])
                        <a href="{{ route('people.show', $item['from_hash']) }}">{{ $item['from_name'] }}</a>
                      @else
                        {{ $item['from_name'] }}
                      @endif
                    </div>
                    <div class="table-cell">{{ $item['type_name'] }}</div>
                    <div class="table-cell">
                      @if ($item['to_hash'])
                        <a href="{{ route('people.show', $item['to_hash']) }}">{{ $item['to_name'] }}</a>
                      @else
                        {{ $item['to_name'] }}
                      @endif
                    </div>
                    <div class="table-cell">{{ $item['reason'] }}</div>
                    <div class="table-cell">
                      <form method="POST" action="{{ route('settings.relationscheck.create-suggestion') }}">
                        @csrf
                        <input type="hidden" name="account_id" value="{{ $item['account_id'] }}">
                        <input type="hidden" name="from_id" value="{{ $item['from_id'] }}">
                        <input type="hidden" name="to_id" value="{{ $item['to_id'] }}">
                        <input type="hidden" name="relationship_type_id" value="{{ $item['type_id'] }}">
                        <button type="submit" class="btn btn-primary btn-small">
                          {{ trans('settings.relationscheck_create_suggestion') }}
                        </button>
                      </form>
                    </div>
                  </li>
                @endforeach
              </ul>

              <form method="POST" action="{{ route('settings.relationscheck.apply-suggestions') }}">
                @csrf
                <button type="submit" class="btn btn-primary">
                  {{ trans('settings.relationscheck_apply_suggestions') }}
                </button>
              </form>
            @endif
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

@endsection
