@extends('admin.layout')

@section('title', '댓글/대댓글 관리')

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
        tr.reported {
            background-color: #ffe6e6 !important;
        }
        tr.reported:hover {
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
        .status.comment {
            background-color: #007bff;
            color: white;
        }
        .status.reply {
            background-color: #6c757d;
            color: white;
        }
        .status.deleted {
            background-color: #dc3545;
            color: white;
        }
        .status.pinned {
            background-color: #ffc107;
            color: #000;
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
            margin: 3% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 900px;
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
        .content-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .report-section {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .report-item {
            padding: 10px;
            margin-bottom: 10px;
            background-color: white;
            border-left: 3px solid #dc3545;
            border-radius: 4px;
        }
        .reply-section {
            margin-top: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }
        .reply-item {
            padding: 8px;
            margin-left: 20px;
            margin-top: 5px;
            border-left: 3px solid #ddd;
            background-color: white;
        }
        .reply-item.deleted {
            opacity: 0.6;
        }
        .loading {
            text-align: center;
            padding: 20px;
        }
    </style>
@endpush

@section('content')
    <h1>댓글/대댓글 관리</h1>
    
    <div class="filter-section">
        <input type="text" id="searchInput" placeholder="작성자 또는 내용 검색...">
        <input type="number" id="portfolioIdInput" placeholder="포트폴리오 ID" style="width: 150px;">
        <select id="commentTypeFilter">
            <option value="">전체</option>
            <option value="comment">댓글</option>
            <option value="reply">대댓글</option>
        </select>
        <select id="isDeletedFilter">
            <option value="">전체</option>
            <option value="false">정상</option>
            <option value="true">삭제됨</option>
        </select>
        <select id="isPinnedFilter">
            <option value="">전체</option>
            <option value="true">고정됨</option>
            <option value="false">고정안됨</option>
        </select>
        <select id="hasReportsFilter">
            <option value="">전체</option>
            <option value="true">신고 있음</option>
            <option value="false">신고 없음</option>
        </select>
        <button onclick="loadComments()">검색</button>
        <button onclick="resetFilters()">초기화</button>
    </div>

    <div id="loading" class="loading" style="display: none;">로딩 중...</div>
    
    <table id="commentTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>타입</th>
                <th>작성자</th>
                <th>내용</th>
                <th>포트폴리오</th>
                <th>대댓글 수</th>
                <th>고정</th>
                <th>신고</th>
                <th>등록일</th>
                <th>수정일</th>
                <th>삭제일</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody id="commentTableBody">
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
            <h2>댓글 수정</h2>
            <form id="editForm">
                <input type="hidden" id="editId">
                <div style="margin-bottom: 15px;">
                    <label>내용:</label>
                    <textarea id="editContent" style="width: 100%; padding: 8px; margin-top: 5px; min-height: 150px;"></textarea>
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
        let currentFilters = {};

        // 페이지 로드 시 댓글 목록 불러오기
        document.addEventListener('DOMContentLoaded', function() {
            loadComments();
            
            // 검색 입력 필드에서 Enter 키 처리
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    loadComments();
                }
            });
        });

        // 댓글 목록 불러오기
        function loadComments(page = 1) {
            currentPage = page;
            
            // 필터 수집
            currentFilters = {
                search: document.getElementById('searchInput').value,
                portfolio_id: document.getElementById('portfolioIdInput').value,
                comment_type: document.getElementById('commentTypeFilter').value,
                is_deleted: document.getElementById('isDeletedFilter').value,
                is_pinned: document.getElementById('isPinnedFilter').value,
                has_reports: document.getElementById('hasReportsFilter').value,
            };
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('commentTableBody').innerHTML = '';

            const url = new URL('/admin/api/comments', window.location.origin);
            url.searchParams.append('page', page);
            url.searchParams.append('per_page', 20);
            
            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key]) {
                    url.searchParams.append(key, currentFilters[key]);
                }
            });

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    if (data.success) {
                        renderComments(data.data.list);
                        renderPagination(data.data.pagination);
                    } else {
                        alert('댓글 목록을 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    document.getElementById('loading').style.display = 'none';
                    console.error('Error:', error);
                    alert('댓글 목록을 불러오는 중 오류가 발생했습니다.');
                });
        }

        // 댓글 목록 렌더링
        function renderComments(comments) {
            const tbody = document.getElementById('commentTableBody');
            tbody.innerHTML = '';

            comments.forEach(comment => {
                const tr = document.createElement('tr');
                if (comment.has_pending_reports) {
                    tr.classList.add('reported');
                }
                if (comment.is_deleted) {
                    tr.classList.add('deleted');
                }

                const typeBadge = comment.type === '댓글' 
                    ? '<span class="status comment">댓글</span>'
                    : '<span class="status reply">대댓글</span>';
                
                const pinnedBadge = comment.is_pinned 
                    ? '<span class="status pinned">📌 고정</span>'
                    : '';
                
                const deletedBadge = comment.is_deleted 
                    ? '<span class="status deleted">삭제됨</span>'
                    : '';

                tr.innerHTML = `
                    <td>${comment.id}</td>
                    <td>${typeBadge}</td>
                    <td>${comment.user.username}</td>
                    <td>
                        <div class="content-preview" title="${comment.content}">
                            ${comment.content}
                        </div>
                    </td>
                    <td>
                        <a href="/admin/portfolios" style="color: #007bff;">
                            #${comment.portfolio.id} ${comment.portfolio.title}
                        </a>
                    </td>
                    <td>${comment.replies_count}</td>
                    <td>${pinnedBadge}</td>
                    <td>
                        ${comment.pending_reports_count > 0 ? 
                            `<span style="color: red; font-weight: bold;">${comment.pending_reports_count}건</span>` : 
                            comment.reports_count > 0 ? `${comment.reports_count}건` : '0건'
                        }
                    </td>
                    <td>${comment.created_at}</td>
                    <td>${comment.updated_at}</td>
                    <td>${comment.deleted_at || '-'}</td>
                    <td>
                        <button class="btn btn-primary" onclick="showDetail(${comment.id})">상세</button>
                        <button class="btn btn-primary" onclick="showEdit(${comment.id})">수정</button>
                        ${!comment.is_deleted ? 
                            `<button class="btn btn-danger" onclick="deleteComment(${comment.id})">삭제</button>` :
                            `<button class="btn btn-success" onclick="restoreComment(${comment.id})">복원</button>`
                        }
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
            prevBtn.onclick = () => loadComments(pagination.current_page - 1);
            paginationDiv.appendChild(prevBtn);

            for (let i = 1; i <= pagination.last_page; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.className = i === pagination.current_page ? 'active' : '';
                btn.onclick = () => loadComments(i);
                paginationDiv.appendChild(btn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.textContent = '다음';
            nextBtn.disabled = pagination.current_page === pagination.last_page;
            nextBtn.onclick = () => loadComments(pagination.current_page + 1);
            paginationDiv.appendChild(nextBtn);
        }

        // 필터 초기화
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('portfolioIdInput').value = '';
            document.getElementById('commentTypeFilter').value = '';
            document.getElementById('isDeletedFilter').value = '';
            document.getElementById('isPinnedFilter').value = '';
            document.getElementById('hasReportsFilter').value = '';
            loadComments(1);
        }

        // 상세 보기
        function showDetail(id) {
            fetch(`/admin/api/comments/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const comment = data.data;
                        
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
                            : '<p>대댓글이 없습니다.</p>';

                        const reportsHtml = comment.reports && comment.reports.length > 0
                            ? comment.reports.map(report => {
                                const statusColor = report.status === 'pending' ? '#ffc107' : 
                                                   report.status === 'resolved' ? '#28a745' : '#6c757d';
                                return `
                                    <div class="report-item">
                                        <strong>${report.user.username}</strong>
                                        <span style="background-color: ${statusColor}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 10px;">
                                            ${report.status}
                                        </span>
                                        <p><strong>유형:</strong> ${report.report_type}</p>
                                        <p><strong>사유:</strong> ${report.reason || '(사유 없음)'}</p>
                                        <small>${report.created_at}</small>
                                    </div>
                                `;
                            }).join('')
                            : '<p>신고 내역이 없습니다.</p>';

                        document.getElementById('modalContent').innerHTML = `
                            <h2>댓글 상세 정보</h2>
                            <p><strong>ID:</strong> ${comment.id}</p>
                            <p><strong>타입:</strong> ${comment.type}</p>
                            <p><strong>작성자:</strong> ${comment.user.username}</p>
                            <p><strong>포트폴리오:</strong> #${comment.portfolio.id} ${comment.portfolio.title}</p>
                            <p><strong>내용:</strong></p>
                            <p style="background-color: #f8f9fa; padding: 10px; border-radius: 4px;">${comment.content}</p>
                            <p><strong>고정 여부:</strong> ${comment.is_pinned ? '고정됨' : '고정 안됨'}</p>
                            <p><strong>삭제 여부:</strong> ${comment.is_deleted ? '삭제됨' : '정상'}</p>
                            <p><strong>등록일:</strong> ${comment.created_at}</p>
                            <p><strong>수정일:</strong> ${comment.updated_at}</p>
                            <p><strong>삭제일:</strong> ${comment.deleted_at || '-'}</p>
                            <p><strong>대댓글 수:</strong> ${comment.replies_count}개</p>
                            ${comment.type === '댓글' ? `
                                <div class="reply-section">
                                    <p><strong>대댓글:</strong></p>
                                    ${repliesHtml}
                                </div>
                            ` : ''}
                            <div class="report-section">
                                <p><strong>신고 내역 (${comment.reports_count}건, 대기중: ${comment.pending_reports_count}건):</strong></p>
                                ${reportsHtml}
                            </div>
                        `;
                        document.getElementById('detailModal').style.display = 'block';
                    } else {
                        alert('댓글 상세 정보를 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('댓글 상세 정보를 불러오는 중 오류가 발생했습니다.');
                });
        }

        // 모달 닫기
        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // 수정 모달 열기
        function showEdit(id) {
            fetch(`/admin/api/comments/${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const comment = data.data;
                        document.getElementById('editId').value = comment.id;
                        document.getElementById('editContent').value = comment.content;
                        document.getElementById('editModal').style.display = 'block';
                    } else {
                        alert('댓글 정보를 불러오는 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('댓글 정보를 불러오는 중 오류가 발생했습니다.');
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
                content: document.getElementById('editContent').value,
            };

            fetch(`/admin/api/comments/${id}`, {
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
                        alert('댓글이 수정되었습니다.');
                        closeEditModal();
                        loadComments(currentPage);
                    } else {
                        alert(data.message || '댓글 수정 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('댓글 수정 중 오류가 발생했습니다.');
                });
        });

        // 삭제
        function deleteComment(id) {
            if (!confirm('정말로 이 댓글을 삭제하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/comments/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('댓글이 삭제되었습니다.');
                        loadComments(currentPage);
                    } else {
                        alert(data.message || '댓글 삭제 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('댓글 삭제 중 오류가 발생했습니다.');
                });
        }

        // 복원
        function restoreComment(id) {
            if (!confirm('정말로 이 댓글을 복원하시겠습니까?')) {
                return;
            }

            fetch(`/admin/api/comments/${id}/restore`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('댓글이 복원되었습니다.');
                        loadComments(currentPage);
                    } else {
                        alert(data.message || '댓글 복원 중 오류가 발생했습니다.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('댓글 복원 중 오류가 발생했습니다.');
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

