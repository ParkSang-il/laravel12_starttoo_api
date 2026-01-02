@extends('admin.layout')

@section('title', '관리자 로그인 로그')

@push('styles')
    <style>
        .filter-section {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-section input, .filter-section button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        .filter-section button {
            background: #007bff;
            color: #fff;
            border: none;
            cursor: pointer;
        }
        .filter-section button:hover {
            background: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 13px;
        }
        th, td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #fff;
        }
        .tag.login { background: #007bff; }
        .tag.logout { background: #6c757d; }
        .tag.password_mismatch { background: #dc3545; }
        .tag.success { background: #28a745; }
        .tag.fail { background: #dc3545; }
        .pagination {
            margin-top: 15px;
            display: flex;
            gap: 8px;
        }
        .pagination button {
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: #fff;
            cursor: pointer;
            border-radius: 4px;
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .message-alert {
            padding: 12px 16px;
            margin-bottom: 15px;
            border-radius: 4px;
            display: none;
        }
        .message-alert.show { display: block; }
        .message-alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
@endpush

@section('content')
    <h1>관리자 로그인 로그</h1>

    <div id="messageAlert" class="message-alert"></div>

    <div class="filter-section">
        <input type="text" id="filterUsername" placeholder="아이디 검색">
        <input type="text" id="filterIp" placeholder="IP 검색">
        <input type="date" id="filterDateFrom">
        <input type="date" id="filterDateTo">
        <button onclick="loadLogs(1)">검색</button>
        <button onclick="resetFilters()">초기화</button>
    </div>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>아이디</th>
            <th>IP</th>
            <th>액션</th>
            <th>성공여부</th>
            <th>실패사유</th>
            <th>일자</th>
        </tr>
        </thead>
        <tbody id="logTbody">
        </tbody>
    </table>

    <div class="pagination" id="pagination"></div>
@endsection

@push('scripts')
    <script>
        let currentPage = 1;

        document.addEventListener('DOMContentLoaded', () => {
            loadLogs();
        });

        function showMessage(message, type = 'success') {
            const el = document.getElementById('messageAlert');
            el.textContent = message;
            el.className = `message-alert ${type} show`;
            setTimeout(() => el.classList.remove('show'), 4000);
        }

        function resetFilters() {
            document.getElementById('filterUsername').value = '';
            document.getElementById('filterIp').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            loadLogs(1);
        }

        function loadLogs(page = 1) {
            currentPage = page;
            const username = document.getElementById('filterUsername').value;
            const ip = document.getElementById('filterIp').value;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;

            const url = new URL('/admin/api/admin/login-logs', window.location.origin);
            url.searchParams.append('page', page);
            url.searchParams.append('per_page', 20);
            if (username) url.searchParams.append('username', username);
            if (ip) url.searchParams.append('ip', ip);
            if (dateFrom) url.searchParams.append('date_from', dateFrom);
            if (dateTo) url.searchParams.append('date_to', dateTo);

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showMessage(data.message || '로그 조회 중 오류가 발생했습니다.', 'error');
                        return;
                    }
                    renderLogs(data.data.data || data.data);
                    renderPagination(data.data);
                })
                .catch(err => {
                    console.error(err);
                    showMessage('로그 조회 중 오류가 발생했습니다.', 'error');
                });
        }

        function renderLogs(list) {
            const tbody = document.getElementById('logTbody');
            tbody.innerHTML = '';

            list.forEach(item => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${item.id}</td>
                    <td>${item.username || '-'}</td>
                    <td>${item.ip_address || '-'}</td>
                    <td><span class="tag ${item.action}">${actionLabel(item.action)}</span></td>
                    <td><span class="tag ${item.is_success ? 'success' : 'fail'}">${item.is_success ? '성공' : '실패'}</span></td>
                    <td>${item.failure_reason || '-'}</td>
                    <td>${item.created_at || '-'}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function renderPagination(pagination) {
            const pager = document.getElementById('pagination');
            pager.innerHTML = '';
            if (!pagination || !pagination.last_page) return;

            const { current_page, last_page } = pagination;

            const prev = document.createElement('button');
            prev.textContent = '이전';
            prev.disabled = current_page === 1;
            prev.onclick = () => loadLogs(current_page - 1);
            pager.appendChild(prev);

            for (let i = 1; i <= last_page; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.style.background = i === current_page ? '#007bff' : '#fff';
                btn.style.color = i === current_page ? '#fff' : '#000';
                btn.onclick = () => loadLogs(i);
                pager.appendChild(btn);
            }

            const next = document.createElement('button');
            next.textContent = '다음';
            next.disabled = current_page === last_page;
            next.onclick = () => loadLogs(current_page + 1);
            pager.appendChild(next);
        }

        function actionLabel(action) {
            switch (action) {
                case 'login': return '로그인';
                case 'logout': return '로그아웃';
                case 'password_mismatch': return '비밀번호 불일치';
                default: return action;
            }
        }
    </script>
@endpush

