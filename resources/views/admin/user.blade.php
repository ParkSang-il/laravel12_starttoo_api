@extends('admin.layout')

@section('title', '회원 관리')

@push('styles')
    <style>
        .filter-section {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-section input, .filter-section select, .filter-section button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .filter-section input {
            flex: 1;
            min-width: 200px;
        }
        .filter-section button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
        .filter-section button:hover {
            background-color: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        tr.suspended {
            background-color: #ffe6e6 !important;
        }
        tr.suspended:hover {
            background-color: #ffcccc !important;
        }
        tr.deleted {
            opacity: 0.6;
        }
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-right: 5px;
        }
        .status.normal {
            background-color: #28a745;
            color: white;
        }
        .status.business {
            background-color: #007bff;
            color: white;
        }
        .status.suspended {
            background-color: #dc3545;
            color: white;
        }
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            margin-right: 3px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 800px;
            border-radius: 8px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover,
        .close:focus {
            color: black;
        }
        .loading {
            text-align: center;
            padding: 20px;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .pagination button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background-color: white;
            cursor: pointer;
            border-radius: 4px;
        }
        .pagination button:hover:not(:disabled) {
            background-color: #f8f9fa;
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .message-alert {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: none;
        }
        .message-alert.show {
            display: block;
        }
        .message-alert.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-alert.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message-alert.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
    </style>
@endpush

@section('content')
    <h1>회원 관리</h1>

    <!-- 메시지 표시 영역 -->
    <div id="messageAlert" class="message-alert"></div>

    <div class="filter-section">
        <input type="text" id="searchInput" placeholder="닉네임 또는 전화번호 검색...">
        <select id="userTypeFilter">
            <option value="">전체</option>
            <option value="1">일반회원</option>
            <option value="2">사업자</option>
        </select>
        <select id="suspensionStatusFilter">
            <option value="">전체</option>
            <option value="not_suspended">정상</option>
            <option value="suspended">정지</option>
        </select>
        <button onclick="loadUsers()">검색</button>
        <button onclick="resetFilters()">초기화</button>
    </div>

    <div id="loading" class="loading" style="display: none;">로딩 중...</div>

    <table id="userTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>닉네임</th>
                <th>전화번호</th>
                <th>회원유형</th>
                <th>정지상태</th>
                <th>정지사유</th>
                <th>가입일</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody id="userTableBody">
            <!-- 데이터가 여기에 동적으로 로드됩니다 -->
        </tbody>
    </table>

    <div class="pagination" id="pagination">
        <!-- 페이지네이션이 여기에 동적으로 생성됩니다 -->
    </div>

    <!-- 상세 모달 -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <div id="modalContent">
                <!-- 상세 내용이 여기에 표시됩니다 -->
            </div>
        </div>
    </div>

    <!-- 정지 모달 -->
    <div id="suspendModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeSuspendModal()">&times;</span>
            <h2>회원 정지</h2>
            <form id="suspendForm">
                <input type="hidden" id="suspendUserId">
                <div style="margin-bottom: 15px;">
                    <label>정지 기간:</label>
                    <select id="suspensionType" style="width: 100%; padding: 8px; margin-top: 5px;" required>
                        <option value="">선택하세요</option>
                        <option value="5days">5일 정지</option>
                        <option value="10days">10일 정지</option>
                        <option value="15days">15일 정지</option>
                        <option value="30days">30일 정지</option>
                        <option value="permanent">영구정지</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>정지 사유:</label>
                    <textarea id="suspensionReason" style="width: 100%; padding: 8px; margin-top: 5px; min-height: 100px;" required placeholder="정지 사유를 입력하세요"></textarea>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-danger">정지 처리</button>
                    <button type="button" class="btn" onclick="closeSuspendModal()">취소</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 로그인 기록 모달 -->
    <div id="loginLogModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeLoginLogModal()">&times;</span>
            <h2 id="loginLogModalTitle">로그인 기록</h2>
            <div id="loginLogModalContent">
                <!-- 로그인 기록이 여기에 표시됩니다 -->
            </div>
        </div>
    </div>

    <!-- 사업자 가입신청 정보 모달 -->
    <div id="businessVerificationModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeBusinessVerificationModal()">&times;</span>
            <h2 id="businessVerificationModalTitle">사업자 가입신청 정보</h2>
            <div id="businessVerificationModalContent">
                <!-- 사업자 가입신청 정보가 여기에 표시됩니다 -->
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        let currentPage = 1;

        // 메시지 표시 함수
        function showMessage(message, type = 'success') {
            const messageAlert = document.getElementById('messageAlert');
            messageAlert.textContent = message;
            messageAlert.className = `message-alert ${type} show`;

            // 5초 후 자동으로 숨김
            setTimeout(() => {
                messageAlert.classList.remove('show');
            }, 5000);
        }

        // 페이지 로드 시 회원 목록 불러오기
        document.addEventListener('DOMContentLoaded', () => {
            loadUsers();
        });

        // 회원 목록 불러오기
        function loadUsers(page = 1) {
            currentPage = page;
            const search = document.getElementById('searchInput').value;
            const userType = document.getElementById('userTypeFilter').value;
            const suspensionStatus = document.getElementById('suspensionStatusFilter').value;

            document.getElementById('loading').style.display = 'block';
            document.getElementById('userTableBody').innerHTML = '';

            const url = new URL('/admin/api/users', window.location.origin);
            url.searchParams.append('page', page);
            if (search) {
                url.searchParams.append('search', search);
            }
            if (userType) {
                url.searchParams.append('user_type', userType);
            }
            if (suspensionStatus) {
                url.searchParams.append('suspension_status', suspensionStatus);
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    if (data.success) {
                        renderUsers(data.data.list);
                        renderPagination(data.data.pagination);
                    } else {
                        showMessage('회원 목록을 불러오는 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('loading').style.display = 'none';
                    console.error('Error:', error);
                    showMessage('회원 목록을 불러오는 중 오류가 발생했습니다.', 'error');
                });
        }

        // 회원 목록 렌더링
        function renderUsers(users) {
            const tbody = document.getElementById('userTableBody');
            tbody.innerHTML = '';

            users.forEach(user => {
                const tr = document.createElement('tr');
                if (user.is_suspended) {
                    tr.classList.add('suspended');
                }
                if (user.deleted_at) {
                    tr.classList.add('deleted');
                }

                const suspensionStatusHtml = user.is_suspended
                    ? `<span class="status suspended">${user.suspension_status_text || '정지'}</span>`
                    : '<span class="status normal">정상</span>';

                tr.innerHTML = `
                    <td>${user.id}</td>
                    <td>${user.username}</td>
                    <td>${user.phone}</td>
                    <td>
                        ${user.user_type === 2
                            ? `<span class="status business" style="cursor: pointer;" onclick="showBusinessVerification(${user.id})" title="사업자 가입신청 정보 보기">${user.user_type_text} 📋</span>`
                            : `<span class="status ${user.user_type === 1 ? 'normal' : 'business'}">${user.user_type_text}</span>`
                        }
                    </td>
                    <td>${suspensionStatusHtml}</td>
                    <td>${user.suspension_reason || '-'}</td>
                    <td>${user.created_at}</td>
                    <td>
                        <button class="btn btn-primary" onclick="showDetail(${user.id})">상세</button>
                        ${!user.is_suspended ?
                            `<button class="btn btn-danger" onclick="showSuspend(${user.id})">정지</button>` :
                            `<button class="btn btn-success" onclick="unsuspendUser(${user.id})">정지해제</button>`
                        }
                        <button class="btn btn-warning" onclick="showLoginLogs(${user.id})">로그인기록</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // 페이지네이션 렌더링
        function renderPagination(pagination) {
            const paginationDiv = document.getElementById('pagination');
            paginationDiv.innerHTML = '';

            const prevBtn = document.createElement('button');
            prevBtn.textContent = '이전';
            prevBtn.disabled = pagination.current_page === 1;
            prevBtn.onclick = () => loadUsers(pagination.current_page - 1);
            paginationDiv.appendChild(prevBtn);

            for (let i = 1; i <= pagination.last_page; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.textContent = i;
                pageBtn.style.backgroundColor = i === pagination.current_page ? '#007bff' : 'white';
                pageBtn.style.color = i === pagination.current_page ? 'white' : 'black';
                pageBtn.onclick = () => loadUsers(i);
                paginationDiv.appendChild(pageBtn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.textContent = '다음';
            nextBtn.disabled = pagination.current_page === pagination.last_page;
            nextBtn.onclick = () => loadUsers(pagination.current_page + 1);
            paginationDiv.appendChild(nextBtn);
        }

        // 필터 초기화
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('userTypeFilter').value = '';
            document.getElementById('suspensionStatusFilter').value = '';
            loadUsers(1);
        }

        // 상세 보기
        function showDetail(id) {
            fetch(`/admin/api/users/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.data;
                        const suspensionStatusHtml = user.is_suspended
                            ? `<p><strong>정지 상태:</strong> <span style="color: red;">${user.suspension_status_text}</span></p>
                               <p><strong>정지 유형:</strong> ${user.suspension_type || '-'}</p>
                               <p><strong>정지 사유:</strong> ${user.suspension_reason || '-'}</p>
                               <p><strong>정지 일시:</strong> ${user.suspended_at || '-'}</p>
                               <p><strong>정지 해제 예정:</strong> ${user.suspended_until || '-'}</p>
                               <p><strong>정지 처리자:</strong> ${user.suspended_by ? user.suspended_by.username : '-'}</p>`
                            : '<p><strong>정지 상태:</strong> 정상</p>';

                        document.getElementById('modalContent').innerHTML = `
                            <h2>회원 상세 정보</h2>
                            <p><strong>ID:</strong> ${user.id}</p>
                            <p><strong>닉네임:</strong> ${user.username}</p>
                            <p><strong>전화번호:</strong> ${user.phone}</p>
                            <p><strong>회원 유형:</strong> ${user.user_type_text}</p>
                            <p><strong>전화번호 인증일:</strong> ${user.phone_verified_at || '-'}</p>
                            ${suspensionStatusHtml}
                            <p><strong>포트폴리오 수:</strong> ${user.portfolios_count || 0}</p>
                            <p><strong>댓글 수:</strong> ${user.comments_count || 0}</p>
                            <p><strong>가입일:</strong> ${user.created_at}</p>
                            <p><strong>수정일:</strong> ${user.updated_at}</p>
                            ${user.deleted_at ? `<p><strong>삭제일:</strong> ${user.deleted_at}</p>` : ''}
                        `;
                        document.getElementById('detailModal').style.display = 'block';
                    } else {
                        showMessage('회원 정보를 불러오는 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('회원 정보를 불러오는 중 오류가 발생했습니다.', 'error');
                });
        }

        // 모달 닫기
        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // 정지 모달 열기
        function showSuspend(id) {
            document.getElementById('suspendUserId').value = id;
            document.getElementById('suspensionType').value = '';
            document.getElementById('suspensionReason').value = '';
            document.getElementById('suspendModal').style.display = 'block';
        }

        // 정지 모달 닫기
        function closeSuspendModal() {
            document.getElementById('suspendModal').style.display = 'none';
        }

        // 정지 폼 제출
        document.getElementById('suspendForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const id = document.getElementById('suspendUserId').value;
            const suspensionType = document.getElementById('suspensionType').value;
            const suspensionReason = document.getElementById('suspensionReason').value;

            if (!suspensionType || !suspensionReason.trim()) {
                showMessage('정지 기간과 사유를 모두 입력해주세요.', 'warning');
                return;
            }

            fetch(`/admin/api/users/${id}/suspend`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    suspension_type: suspensionType,
                    suspension_reason: suspensionReason
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        closeSuspendModal();
                        loadUsers(currentPage);
                    } else {
                        showMessage(data.message || '회원 정지 처리 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('회원 정지 처리 중 오류가 발생했습니다.', 'error');
                });
        });

        // 정지 해제
        function unsuspendUser(id) {
            if (!confirm('정말로 이 회원의 정지를 해제하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/users/${id}/unsuspend`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        loadUsers(currentPage);
                    } else {
                        showMessage(data.message || '회원 정지 해제 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('회원 정지 해제 중 오류가 발생했습니다.', 'error');
                });
        }

        // 로그인 기록 보기
        function showLoginLogs(id) {
            fetch(`/admin/api/users/${id}/login-logs`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const logs = data.data.logs;
                        const title = `${data.data.username} - 로그인 기록`;

                        const logsHtml = logs.length > 0
                            ? logs.map(log => {
                                const successBadge = log.is_success
                                    ? '<span style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">성공</span>'
                                    : '<span style="background-color: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">실패</span>';

                                return `
                                    <div style="margin-bottom: 15px; padding: 15px; background-color: #f8f9fa; border-radius: 4px; border-left: 4px solid ${log.is_success ? '#28a745' : '#dc3545'};">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                            <strong>${log.created_at}</strong>
                                            ${successBadge}
                                        </div>
                                        <p><strong>IP 주소:</strong> ${log.ip_address || '-'}</p>
                                        <p><strong>디바이스:</strong> ${log.device_type || '-'} ${log.device_model ? `(${log.device_model})` : ''}</p>
                                        <p><strong>OS:</strong> ${log.os || '-'} | <strong>브라우저:</strong> ${log.browser || '-'}</p>
                                        ${log.failure_reason ? `<p><strong>실패 사유:</strong> <span style="color: red;">${log.failure_reason}</span></p>` : ''}
                                    </div>
                                `;
                            }).join('')
                            : '<p>로그인 기록이 없습니다.</p>';

                        document.getElementById('loginLogModalTitle').textContent = title;
                        document.getElementById('loginLogModalContent').innerHTML = `
                            <p><strong>총 로그인 기록: ${data.data.pagination.total}건</strong></p>
                            <div style="margin-top: 20px; max-height: 500px; overflow-y: auto;">
                                ${logsHtml}
                            </div>
                        `;
                        document.getElementById('loginLogModal').style.display = 'block';
                    } else {
                        showMessage('로그인 기록을 불러오는 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('로그인 기록을 불러오는 중 오류가 발생했습니다.', 'error');
                });
        }

        // 로그인 기록 모달 닫기
        function closeLoginLogModal() {
            document.getElementById('loginLogModal').style.display = 'none';
        }

        // 사업자 가입신청 정보 보기
        function showBusinessVerification(id) {
            fetch(`/admin/api/users/${id}/business-verification`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const verification = data.data;
                        const statusBadge = verification.status === 'pending'
                            ? '<span style="background-color: #ffc107; color: #000; padding: 4px 8px; border-radius: 4px; font-size: 11px;">대기중</span>'
                            : verification.status === 'approved'
                            ? '<span style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">승인됨</span>'
                            : '<span style="background-color: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">거절됨</span>';

                        const availableRegions = Array.isArray(verification.available_regions)
                            ? verification.available_regions.join(', ')
                            : '-';
                        const mainStyles = Array.isArray(verification.main_styles)
                            ? verification.main_styles.join(', ')
                            : '-';

                        document.getElementById('businessVerificationModalTitle').textContent = `${verification.username} - 사업자 가입신청 정보`;
                        document.getElementById('businessVerificationModalContent').innerHTML = `
                            <div style="margin-bottom: 20px;">
                                <p><strong>상태:</strong> ${statusBadge}</p>
                                <p><strong>상호명:</strong> ${verification.business_name || '-'}</p>
                                <p><strong>사업자등록번호:</strong> ${verification.business_number || '-'}</p>
                                <p><strong>주소:</strong> ${verification.address || '-'} ${verification.address_detail || ''}</p>
                                <p><strong>연락처 공개:</strong> ${verification.contact_phone_public ? '예' : '아니오'}</p>
                                <p><strong>작업 가능 지역:</strong> ${availableRegions}</p>
                                <p><strong>주요 스타일:</strong> ${mainStyles}</p>
                                ${verification.business_certificate ? `<p><strong>사업자등록증:</strong> <a href="${verification.business_certificate}" target="_blank">파일 보기</a></p>` : ''}
                                ${verification.license_certificate ? `<p><strong>문신사 자격증:</strong> <a href="${verification.license_certificate}" target="_blank">파일 보기</a></p>` : ''}
                                ${verification.safety_education_certificate ? `<p><strong>위생·안전 교육이수증:</strong> <a href="${verification.safety_education_certificate}" target="_blank">파일 보기</a></p>` : ''}
                                ${verification.rejected_reason ? `<p><strong>거절 사유:</strong> <span style="color: red;">${verification.rejected_reason}</span></p>` : ''}
                                ${verification.approved_at ? `<p><strong>승인일시:</strong> ${verification.approved_at}</p>` : ''}
                                <p><strong>신청일:</strong> ${verification.created_at}</p>
                                <p><strong>수정일:</strong> ${verification.updated_at}</p>
                            </div>
                        `;
                        document.getElementById('businessVerificationModal').style.display = 'block';
                    } else {
                        showMessage(data.message || '사업자 가입신청 정보를 불러오는 중 오류가 발생했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('사업자 가입신청 정보를 불러오는 중 오류가 발생했습니다.', 'error');
                });
        }

        // 사업자 가입신청 정보 모달 닫기
        function closeBusinessVerificationModal() {
            document.getElementById('businessVerificationModal').style.display = 'none';
        }

        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const detailModal = document.getElementById('detailModal');
            const suspendModal = document.getElementById('suspendModal');
            const loginLogModal = document.getElementById('loginLogModal');
            const businessVerificationModal = document.getElementById('businessVerificationModal');
            if (event.target === detailModal) {
                closeModal();
            }
            if (event.target === suspendModal) {
                closeSuspendModal();
            }
            if (event.target === loginLogModal) {
                closeLoginLogModal();
            }
            if (event.target === businessVerificationModal) {
                closeBusinessVerificationModal();
            }
        }
    </script>
@endpush

