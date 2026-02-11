@extends('layouts.librenmsv1')

@section('title', __('Mist Organizations'))

@section('content')
<div class="container-fluid">
    <x-panel title="{{ __('Mist Organizations') }}">
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('API URL') }}</th>
                        <th>{{ __('Org ID') }}</th>
                        <th>{{ __('Sites') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orgs as $org)
                        <tr>
                            <td>{{ $org->name }}</td>
                            <td>{{ $org->api_url }}</td>
                            <td><code>{{ $org->org_id }}</code></td>
                            <td>
                                @if(empty($org->site_ids))
                                    <span class="text-muted">{{ __('All sites') }}</span>
                                @else
                                    {{ count(explode(',', $org->site_ids)) }} {{ __('sites') }}
                                @endif
                            </td>
                            <td>
                                @if($org->enabled)
                                    <span class="label label-success">{{ __('Enabled') }}</span>
                                @else
                                    <span class="label label-default">{{ __('Disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#editModal{{ $org->id }}">
                                    <i class="fa fa-pencil"></i> {{ __('Edit') }}
                                </button>
                                <form action="{{ route('mist-orgs.destroy', $org) }}" method="POST" style="display: inline;" onsubmit="return confirm('{{ __('Are you sure?') }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fa fa-trash"></i> {{ __('Delete') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">{{ __('No Mist organizations configured.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="row">
            <div class="col-md-12">
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addModal">
                    <i class="fa fa-plus"></i> {{ __('Add Mist Organization') }}
                </button>
            </div>
        </div>
    </x-panel>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('mist-orgs.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('Add Mist Organization') }}</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="name">{{ __('Name') }}</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="api_url">{{ __('API URL') }}</label>
                        <input type="url" class="form-control" id="api_url" name="api_url" placeholder="https://api.mist.com" required>
                    </div>
                    <div class="form-group">
                        <label for="api_key">{{ __('API Key') }}</label>
                        <input type="password" class="form-control" id="api_key" name="api_key" required>
                    </div>
                    <div class="form-group">
                        <label for="org_id">{{ __('Organization ID') }}</label>
                        <input type="text" class="form-control" id="org_id" name="org_id" placeholder="UUID" required>
                    </div>
                    <div class="form-group">
                        <label for="site_ids">{{ __('Site IDs') }} <small class="text-muted">({{ __('Optional, comma-separated') }})</small></label>
                        <textarea class="form-control" id="site_ids" name="site_ids" rows="3" placeholder="{{ __('Leave empty to monitor all sites') }}"></textarea>
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" value="1" checked> {{ __('Enabled') }}
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Add') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modals -->
@foreach($orgs as $org)
<div class="modal fade" id="editModal{{ $org->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('mist-orgs.update', $org) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('Edit Mist Organization') }}</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="name{{ $org->id }}">{{ __('Name') }}</label>
                        <input type="text" class="form-control" id="name{{ $org->id }}" name="name" value="{{ $org->name }}" required>
                    </div>
                    <div class="form-group">
                        <label for="api_url{{ $org->id }}">{{ __('API URL') }}</label>
                        <input type="url" class="form-control" id="api_url{{ $org->id }}" name="api_url" value="{{ $org->api_url }}" required>
                    </div>
                    <div class="form-group">
                        <label for="api_key{{ $org->id }}">{{ __('API Key') }}</label>
                        <input type="password" class="form-control" id="api_key{{ $org->id }}" name="api_key" value="{{ $org->api_key }}" required>
                    </div>
                    <div class="form-group">
                        <label for="org_id{{ $org->id }}">{{ __('Organization ID') }}</label>
                        <input type="text" class="form-control" id="org_id{{ $org->id }}" name="org_id" value="{{ $org->org_id }}" required>
                    </div>
                    <div class="form-group">
                        <label for="site_ids{{ $org->id }}">{{ __('Site IDs') }} <small class="text-muted">({{ __('Optional, comma-separated') }})</small></label>
                        <textarea class="form-control" id="site_ids{{ $org->id }}" name="site_ids" rows="3">{{ $org->site_ids }}</textarea>
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" value="1" {{ $org->enabled ? 'checked' : '' }}> {{ __('Enabled') }}
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection
