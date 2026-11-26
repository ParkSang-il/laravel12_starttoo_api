@if (session('status'))
  <div role="status" data-flash style="position: fixed; right: 24px; bottom: 24px; z-index: 50; max-width: 360px; padding: 14px 18px; border-radius: 12px; background: #141413; color: #FFFFFF; font-family: 'Noto Sans KR', system-ui, sans-serif; font-size: 14px; font-weight: 700; box-shadow: 0 8px 24px rgba(0,0,0,0.18)">
    {{ session('status') }}
  </div>
@elseif ($errors->any() && ! request()->routeIs('admin.login'))
  <div role="alert" data-flash style="position: fixed; right: 24px; bottom: 24px; z-index: 50; max-width: 360px; padding: 14px 18px; border-radius: 12px; background: #A3301A; color: #FFFFFF; font-family: 'Noto Sans KR', system-ui, sans-serif; font-size: 14px; font-weight: 700; box-shadow: 0 8px 24px rgba(0,0,0,0.18)">
    {{ $errors->first() }}
  </div>
@endif
