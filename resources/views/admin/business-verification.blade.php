@extends('admin.layout')

@section('title', '사업자 가입신청 관리')

@push('styles')
    <style>
        .filter-section {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .filter-section select, .filter-section button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
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
        }
        th, td {
            padding: 12px;
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
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.pending {
            background-color: #ffc107;
            color: #000;
        }
        .status.approved {
            background-color: #28a745;
            color: white;
        }
        .status.rejected {
            background-color: #dc3545;
            color: white;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .image-links {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .image-link {
            color: #007bff;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
        }
        .image-link:hover {
            text-decoration: underline;
            color: #0056b3;
        }
        .image-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            overflow: auto;
        }
        .image-modal-content {
            position: relative;
            margin: auto;
            padding: 20px;
            width: 90%;
            max-width: 1200px;
            text-align: center;
        }
        .image-modal img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 8px;
        }
        .image-modal-close {
            position: absolute;
            top: 20px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
        }
        .image-modal-close:hover {
            color: #fff;
        }
        .btn-approve {
            background-color: #28a745;
            color: white;
        }
        .btn-approve:hover {
            background-color: #218838;
        }
        .btn-reject {
            background-color: #dc3545;
            color: white;
        }
        .btn-reject:hover {
            background-color: #c82333;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            width: 500px;
        }
        .modal-header {
            margin-bottom: 20px;
        }
        .modal-body textarea {
            width: 100%;
            min-height: 100px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .modal-footer {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
        }
        .pagination button:hover {
            background-color: #f8f9fa;
        }
        .pagination button.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
    </style>
@endpush

@section('content')
    <h1>사업자 가입신청 관리</h1>


        <div class="filter-section">
            <select id="statusFilter">
                <option value="pending">대기중</option>
                <option value="approved">승인됨</option>
                <option value="rejected">반려됨</option>
                <option value="all">전체</option>
            </select>
            <button onclick="loadList()">조회</button>
        </div>

        <div id="loading" class="loading" style="display: none;">로딩 중...</div>

        <table id="verificationTable" style="display: none;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>사용자명</th>
                    <th>전화번호</th>
                    <th>상호명</th>
                    <th>사업자등록번호</th>
                    <th>주소</th>
                    <th>증빙서류</th>
                    <th>상태</th>
                    <th>신청일시</th>
                    <th>작업</th>
                </tr>
            </thead>
            <tbody id="verificationTableBody">
            </tbody>
        </table>

        <div id="pagination" class="pagination"></div>
    </div>

    <!-- 반려 사유 입력 모달 -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>반려 사유 작성</h3>
            </div>
            <div class="modal-body">
                <textarea id="rejectedReason" placeholder="반려 사유를 입력해주세요."></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeRejectModal()">취소</button>
                <button class="btn btn-reject" onclick="confirmReject()">반려</button>
            </div>
        </div>
    </div>

    <!-- 이미지 확대 보기 모달 -->
    <div id="imageModal" class="image-modal">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <div class="image-modal-content">
            <h3 id="imageModalTitle" style="color: white; margin-bottom: 20px;"></h3>
            <img id="imageModalImg" src="" alt="">
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        let currentPage = 1;
        let currentStatus = 'pending';
        let currentRejectId = null;

        // 페이지 로드 시 리스트 조회
        window.onload = function() {
            loadList();
        };

        // 리스트 조회
        function loadList() {
            currentStatus = document.getElementById('statusFilter').value;
            const loading = document.getElementById('loading');
            const table = document.getElementById('verificationTable');

            loading.style.display = 'block';
            table.style.display = 'none';

            fetch(`/admin/api/business-verifications?status=${currentStatus}&page=${currentPage}`)
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    table.style.display = 'table';

                    if (data.success) {
                        renderTable(data.data.list);
                        renderPagination(data.data.pagination);
                    } else {
                        alert('리스트 조회 실패: ' + data.message);
                    }
                })
                .catch(error => {
                    loading.style.display = 'none';
                    console.error('Error:', error);
                    alert('리스트 조회 중 오류가 발생했습니다.');
                });
        }

        // 테이블 렌더링
        function renderTable(list) {
            const tbody = document.getElementById('verificationTableBody');
            tbody.innerHTML = '';

            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center;">조회된 데이터가 없습니다.</td></tr>';
                return;
            }

            list.forEach(item => {
                // 이미지 썸네일 생성
                const images = [];
                if (item.business_certificate) {
                    images.push({url: item.business_certificate, label: '사업자등록증'});
                }
                if (item.license_certificate) {
                    images.push({url: item.license_certificate, label: '면허증'});
                }
                if (item.safety_education_certificate) {
                    images.push({url: item.safety_education_certificate, label: '안전교육이수증'});
                }

                const imageHtml = images.length > 0
                    ? `<div class="image-links">
                        ${images.map((img, idx) => `
                            <a href="#" class="image-link" onclick="event.preventDefault(); openImageModal('https://kr.object.ncloudstorage.com${img.url}', '${img.label}'); return false;">
                                ${img.label}
                            </a>
                        `).join('')}
                       </div>`
                    : '-';

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${item.id}</td>
                    <td>${item.user.username || '-'}</td>
                    <td>${item.user.phone || '-'}</td>
                    <td>${item.business_name || '-'}</td>
                    <td>${item.business_number || '-'}</td>
                    <td>${item.address || '-'}</td>
                    <td>${imageHtml}</td>
                    <td><span class="status ${item.status}">${getStatusText(item.status)}</span></td>
                    <td>${item.created_at || '-'}</td>
                    <td>
                        <div class="action-buttons">
                            ${item.status === 'pending' ? `
                                <button class="btn btn-approve" onclick="approveVerification(${item.id})">승인</button>
                                <button class="btn btn-reject" onclick="openRejectModal(${item.id})">반려</button>
                            ` : '-'}
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // 상태 텍스트 변환
        function getStatusText(status) {
            const statusMap = {
                'pending': '대기중',
                'approved': '승인됨',
                'rejected': '반려됨'
            };
            return statusMap[status] || status;
        }

        // 페이지네이션 렌더링
        function renderPagination(pagination) {
            const paginationDiv = document.getElementById('pagination');
            paginationDiv.innerHTML = '';

            if (pagination.last_page <= 1) return;

            // 이전 페이지 버튼
            if (pagination.current_page > 1) {
                const prevBtn = document.createElement('button');
                prevBtn.textContent = '이전';
                prevBtn.onclick = () => {
                    currentPage--;
                    loadList();
                };
                paginationDiv.appendChild(prevBtn);
            }

            // 페이지 번호 버튼
            for (let i = 1; i <= pagination.last_page; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.textContent = i;
                if (i === pagination.current_page) {
                    pageBtn.classList.add('active');
                }
                pageBtn.onclick = () => {
                    currentPage = i;
                    loadList();
                };
                paginationDiv.appendChild(pageBtn);
            }

            // 다음 페이지 버튼
            if (pagination.current_page < pagination.last_page) {
                const nextBtn = document.createElement('button');
                nextBtn.textContent = '다음';
                nextBtn.onclick = () => {
                    currentPage++;
                    loadList();
                };
                paginationDiv.appendChild(nextBtn);
            }
        }

        // 승인 처리
        function approveVerification(id) {
            if (!confirm('정말 승인하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/business-verifications/${id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include',
                body: JSON.stringify({})
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`HTTP error! status: ${response.status}, body: ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('승인되었습니다.');
                    loadList();
                } else {
                    alert('승인 실패: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('승인 처리 중 오류가 발생했습니다: ' + error.message);
            });
        }

        // 이미지 모달 열기
        function openImageModal(imageUrl, imageLabel) {
            document.getElementById('imageModalImg').src = imageUrl;
            document.getElementById('imageModalTitle').textContent = imageLabel;
            document.getElementById('imageModal').style.display = 'block';
        }

        // 이미지 모달 닫기
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        // 이미지 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const imageModal = document.getElementById('imageModal');
            const rejectModal = document.getElementById('rejectModal');
            if (event.target === imageModal) {
                closeImageModal();
            }
            if (event.target === rejectModal) {
                closeRejectModal();
            }
        }

        // 반려 모달 열기
        function openRejectModal(id) {
            currentRejectId = id;
            document.getElementById('rejectModal').style.display = 'block';
            document.getElementById('rejectedReason').value = '';
        }

        // 반려 모달 닫기
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            currentRejectId = null;
        }

        // 반려 확인
        function confirmReject() {
            const reason = document.getElementById('rejectedReason').value.trim();

            if (!reason) {
                alert('반려 사유를 입력해주세요.');
                return;
            }

            if (!confirm('정말 반려하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/business-verifications/${currentRejectId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include',
                body: JSON.stringify({
                    rejected_reason: reason
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`HTTP error! status: ${response.status}, body: ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('반려되었습니다.');
                    closeRejectModal();
                    loadList();
                } else {
                    alert('반려 실패: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('반려 처리 중 오류가 발생했습니다: ' + error.message);
            });
        }

        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target === modal) {
                closeRejectModal();
            }
        }
    </script>
@endpush

