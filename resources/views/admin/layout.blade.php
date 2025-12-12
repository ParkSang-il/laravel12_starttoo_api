<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '관리자')</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #f5f5f5;
            display: flex;
        }
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            min-height: 100vh;
            padding: 20px 0;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        .sidebar-header h2 {
            font-size: 20px;
            font-weight: 600;
        }
        .menu-category {
            margin-bottom: 10px;
        }
        .menu-category-title {
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            color: #bdc3c7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .menu-item {
            padding: 10px 20px 10px 40px;
            color: #ecf0f1;
            text-decoration: none;
            display: block;
            transition: background-color 0.2s;
        }
        .menu-item:hover {
            background-color: #34495e;
        }
        .menu-item.active {
            background-color: #3498db;
            color: white;
        }
        .main-content {
            margin-left: 250px;
            flex: 1;
            padding: 20px;
        }
        .content-container {
            max-width: 1600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 30px;
            color: #333;
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>관리자</h2>
        </div>
        <nav>
            <div class="menu-category">
                <div class="menu-category-title">사업자 관리</div>
                <a href="{{ route('admin.business-verification.index') }}" 
                   class="menu-item {{ request()->routeIs('admin.business-verification.*') ? 'active' : '' }}">
                    사업자 가입신청 관리
                </a>
                <a href="{{ route('admin.business-edit-request.index') }}" 
                   class="menu-item {{ request()->routeIs('admin.business-edit-request.*') ? 'active' : '' }}">
                    정보수정요청 관리
                </a>
            </div>
            <div class="menu-category">
                <div class="menu-category-title">포트폴리오</div>
                <a href="{{ route('admin.portfolio.index') }}" 
                   class="menu-item {{ request()->routeIs('admin.portfolio.*') ? 'active' : '' }}">
                    포트폴리오 관리
                </a>
                <a href="{{ route('admin.comment.index') }}" 
                   class="menu-item {{ request()->routeIs('admin.comment.*') ? 'active' : '' }}">
                    댓글/대댓글 관리
                </a>
            </div>
        </nav>
    </div>
    <div class="main-content">
        <div class="content-container">
            @yield('content')
        </div>
    </div>
    @stack('scripts')
</body>
</html>

