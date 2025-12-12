@extends('admin.layout')

@section('title', '포트폴리오 관리')

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
        tr.reported {
            background-color: #ffe6e6 !important;
        }
        tr.reported:hover {
            background-color: #ffcccc !important;
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
        .status.public {
            background-color: #28a745;
            color: white;
        }
        .status.private {
            background-color: #6c757d;
            color: white;
        }
        .status.sensitive {
            background-color: #dc3545;
            color: white;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 5px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background-color: white;
            cursor: pointer;
        }
        .pagination button:hover {
            background-color: #f8f9fa;
        }
        .pagination button.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            width: 90%;
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
        .close:hover {
            color: #000;
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin: 10px 0;
        }
        .image-item {
            position: relative;
            width: 100%;
            padding-top: 100%;
            background-color: #f0f0f0;
            border-radius: 4px;
            overflow: hidden;
        }
        .image-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .tag {
            padding: 4px 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            font-size: 12px;
        }
        .comment-section {
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .comment-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .comment-item.deleted {
            opacity: 0.6;
            background-color: #f8f9fa;
        }
        .reply-item {
            padding: 8px;
            margin-left: 30px;
            margin-top: 5px;
            border-left: 3px solid #ddd;
            background-color: #f9f9f9;
        }
        .reply-item.deleted {
            opacity: 0.6;
            background-color: #f0f0f0;
        }
        .loading {
            text-align: center;
            padding: 20px;
        }
    </style>
@endpush

@section('content')
    <h1>포트폴리오 관리</h1>
    
    <div class="filter-section">
        <input type="text" id="searchInput" placeholder="제목 또는 내용 검색...">
        <button onclick="loadPortfolios()">검색</button>
        <button onclick="resetFilters()">초기화</button>
    </div>

    <div id="loading" class="loading" style="display: none;">로딩 중...</div>
    
    <table id="portfolioTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>제목</th>
                <th>작성자</th>
                <th>태그</th>
                <th>이미지 수</th>
                <th>등록일</th>
                <th>신고</th>
                <th>상태</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody id="portfolioTableBody">
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

    <!-- 수정 모달 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2>포트폴리오 수정</h2>
            <form id="editForm">
                <input type="hidden" id="editId">
                <div style="margin-bottom: 15px;">
                    <label>제목:</label>
                    <input type="text" id="editTitle" style="width: 100%; padding: 8px; margin-top: 5px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>내용:</label>
                    <textarea id="editDescription" style="width: 100%; padding: 8px; margin-top: 5px; min-height: 100px;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>공개 여부:</label>
                    <select id="editIsPublic" style="width: 100%; padding: 8px; margin-top: 5px;">
                        <option value="1">공개</option>
                        <option value="0">비공개</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>민감정보:</label>
                    <select id="editIsSensitive" style="width: 100%; padding: 8px; margin-top: 5px;">
                        <option value="0">일반</option>
                        <option value="1">민감정보</option>
                    </select>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">수정</button>
                    <button type="button" class="btn" onclick="closeEditModal()">취소</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        let currentPage = 1;
        let currentSearch = '';

        // 페이지 로드 시 포트폴리오 목록 불러오기
        document.addEventListener('DOMContentLoaded', function() {
            loadPortfolios();
            
            // 검색 입력 필드에서 Enter 키 처리
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    loadPortfolios();
                }
            });
        });

        // 포트폴리오 목록 불러오기
        function loadPortfolios(page = 1) {
            currentPage = page;
            currentSearch = document.getElementById('searchInput').value;
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('portfolioTableBody').innerHTML = '';

            const url = new URL('/admin/api/portfolios', window.location.origin);
            url.searchParams.append('page', page);
            url.searchParams.append('per_page', 15);
            if (currentSearch) {
                url.searchParams.append('search', currentSearch);
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    if (data.success) {
                        renderPortfolios(data.data.list);
                        renderPagination(data.data.pagination);
                    } else {
                        alert('포트폴리오 목록을 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    document.getElementById('loading').style.display = 'none';
                    console.error('Error:', error);
                    alert('포트폴리오 목록을 불러오는 중 오류가 발생했습니다.');
                });
        }

        // 포트폴리오 목록 렌더링
        function renderPortfolios(portfolios) {
            const tbody = document.getElementById('portfolioTableBody');
            tbody.innerHTML = '';

            portfolios.forEach(portfolio => {
                const tr = document.createElement('tr');
                if (portfolio.has_pending_reports) {
                    tr.classList.add('reported');
                }

                const tagsHtml = portfolio.tags.map(tag => 
                    `<span class="tag">${tag.name}</span>`
                ).join('');

                const statusHtml = `
                    ${portfolio.is_public ? '<span class="status public">공개</span>' : '<span class="status private">비공개</span>'}
                    ${portfolio.is_sensitive ? '<span class="status sensitive">민감정보</span>' : ''}
                `;

                tr.innerHTML = `
                    <td>${portfolio.id}</td>
                    <td>${portfolio.title}</td>
                    <td>${portfolio.user.username}</td>
                    <td><div class="tag-list">${tagsHtml}</div></td>
                    <td>${portfolio.images.length}</td>
                    <td>${portfolio.created_at}</td>
                    <td>
                        ${portfolio.pending_reports_count > 0 ? 
                            `<span style="color: red; font-weight: bold;">${portfolio.pending_reports_count}건</span>` : 
                            portfolio.reports_count > 0 ? `${portfolio.reports_count}건` : '0건'
                        }
                    </td>
                    <td>${statusHtml}</td>
                    <td>
                        <button class="btn btn-primary" onclick="showDetail(${portfolio.id})">상세</button>
                        <button class="btn btn-primary" onclick="showEdit(${portfolio.id})">수정</button>
                        ${!portfolio.deleted_at ? 
                            `<button class="btn btn-danger" onclick="deletePortfolio(${portfolio.id})">삭제</button>` :
                            '<span style="color: #999;">삭제됨</span>'
                        }
                        <button class="btn btn-warning" onclick="toggleSensitive(${portfolio.id}, ${!portfolio.is_sensitive})">
                            ${portfolio.is_sensitive ? '민감정보 해제' : '민감정보 설정'}
                        </button>
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
            prevBtn.onclick = () => loadPortfolios(pagination.current_page - 1);
            paginationDiv.appendChild(prevBtn);

            for (let i = 1; i <= pagination.last_page; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.className = i === pagination.current_page ? 'active' : '';
                btn.onclick = () => loadPortfolios(i);
                paginationDiv.appendChild(btn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.textContent = '다음';
            nextBtn.disabled = pagination.current_page === pagination.last_page;
            nextBtn.onclick = () => loadPortfolios(pagination.current_page + 1);
            paginationDiv.appendChild(nextBtn);
        }

        // 필터 초기화
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            loadPortfolios(1);
        }

        // 상세 보기
        function showDetail(id) {
            fetch(`/admin/api/portfolios/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const portfolio = data.data;
                        const imagesHtml = portfolio.images.map(img => 
                            `<div class="image-item"><img src="${img.image_url}" alt="Image"></div>`
                        ).join('');

                        const tagsHtml = portfolio.tags.map(tag => 
                            `<span class="tag">${tag.name}</span>`
                        ).join('');

                        const commentsHtml = portfolio.comments.map(comment => {
                            const deletedClass = comment.is_deleted ? 'deleted' : '';
                            
                            // 대댓글 HTML 생성
                            const repliesHtml = comment.replies && comment.replies.length > 0 
                                ? comment.replies.map(reply => {
                                    const replyDeletedClass = reply.is_deleted ? 'deleted' : '';
                                    return `
                                        <div class="reply-item ${replyDeletedClass}">
                                            <strong>${reply.user.username}</strong>
                                            ${reply.is_deleted ? '<span style="color: #999;">(삭제됨)</span>' : ''}
                                            <p>${reply.content}</p>
                                            <small>${reply.created_at}</small>
                                        </div>
                                    `;
                                }).join('')
                                : '';
                            
                            return `
                                <div class="comment-item ${deletedClass}">
                                    ${comment.is_pinned ? '<span style="color: #ff6b6b; font-weight: bold;">📌 고정</span> ' : ''}
                                    <strong>${comment.user.username}</strong>
                                    ${comment.is_deleted ? '<span style="color: #999;">(삭제됨)</span>' : ''}
                                    <p>${comment.content}</p>
                                    <small>${comment.created_at} | 대댓글: ${comment.replies_count}개</small>
                                    ${repliesHtml ? `<div style="margin-top: 10px;">${repliesHtml}</div>` : ''}
                                </div>
                            `;
                        }).join('');

                        document.getElementById('modalContent').innerHTML = `
                            <h2>${portfolio.title}</h2>
                            <p><strong>작성자:</strong> ${portfolio.user.username}</p>
                            <p><strong>등록일:</strong> ${portfolio.created_at}</p>
                            <p><strong>내용:</strong></p>
                            <p>${portfolio.description || '(내용 없음)'}</p>
                            <p><strong>태그:</strong></p>
                            <div class="tag-list">${tagsHtml}</div>
                            <p><strong>이미지:</strong></p>
                            <div class="image-gallery">${imagesHtml}</div>
                            <div class="comment-section">
                                <p><strong>댓글 (${portfolio.comments.length}개):</strong></p>
                                ${commentsHtml || '<p>댓글이 없습니다.</p>'}
                            </div>
                            <p><strong>신고:</strong> ${portfolio.pending_reports_count}건 (대기중) / 총 ${portfolio.reports_count}건</p>
                            <p><strong>상태:</strong> 
                                ${portfolio.is_public ? '공개' : '비공개'} | 
                                ${portfolio.is_sensitive ? '민감정보' : '일반'}
                            </p>
                        `;
                        document.getElementById('detailModal').style.display = 'block';
                    } else {
                        alert('포트폴리오 상세 정보를 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('포트폴리오 상세 정보를 불러오는 중 오류가 발생했습니다.');
                });
        }

        // 모달 닫기
        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // 수정 모달 열기
        function showEdit(id) {
            fetch(`/admin/api/portfolios/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const portfolio = data.data;
                        document.getElementById('editId').value = portfolio.id;
                        document.getElementById('editTitle').value = portfolio.title;
                        document.getElementById('editDescription').value = portfolio.description || '';
                        document.getElementById('editIsPublic').value = portfolio.is_public ? '1' : '0';
                        document.getElementById('editIsSensitive').value = portfolio.is_sensitive ? '1' : '0';
                        document.getElementById('editModal').style.display = 'block';
                    } else {
                        alert('포트폴리오 정보를 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('포트폴리오 정보를 불러오는 중 오류가 발생했습니다.');
                });
        }

        // 수정 모달 닫기
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // 수정 폼 제출
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const id = document.getElementById('editId').value;
            const data = {
                title: document.getElementById('editTitle').value,
                description: document.getElementById('editDescription').value,
                is_public: document.getElementById('editIsPublic').value === '1',
                is_sensitive: document.getElementById('editIsSensitive').value === '1',
            };

            fetch(`/admin/api/portfolios/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify(data)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('포트폴리오가 수정되었습니다.');
                        closeEditModal();
                        loadPortfolios(currentPage);
                    } else {
                        alert(data.message || '포트폴리오 수정 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('포트폴리오 수정 중 오류가 발생했습니다.');
                });
        });

        // 삭제
        function deletePortfolio(id) {
            if (!confirm('정말로 이 포트폴리오를 삭제하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/portfolios/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('포트폴리오가 삭제되었습니다.');
                        loadPortfolios(currentPage);
                    } else {
                        alert(data.message || '포트폴리오 삭제 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('포트폴리오 삭제 중 오류가 발생했습니다.');
                });
        }

        // 민감정보 처리
        function toggleSensitive(id, isSensitive) {
            fetch(`/admin/api/portfolios/${id}/toggle-sensitive`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ is_sensitive: isSensitive })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        loadPortfolios(currentPage);
                    } else {
                        alert(data.message || '민감정보 처리 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('민감정보 처리 중 오류가 발생했습니다.');
                });
        }

        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const detailModal = document.getElementById('detailModal');
            const editModal = document.getElementById('editModal');
            if (event.target === detailModal) {
                closeModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        }
    </script>
@endpush

