@extends('admin.layout')

@section('title', '관리자 계정 추가')

@push('styles')
    <style>
        .form-container {
            max-width: 600px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #007bff;
        }
        .btn-submit {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-submit:hover {
            background-color: #0056b3;
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .error-message {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
@endpush

@section('content')
    <h1>관리자 계정 추가</h1>
    
    <div class="form-container">
        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.auth.create') }}">
            @csrf
            <div class="form-group">
                <label for="username">아이디 *</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" required>
                @error('username')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label for="password">비밀번호 *</label>
                <input type="password" id="password" name="password" required minlength="4">
                @error('password')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label for="password_confirmation">비밀번호 확인 *</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="4">
            </div>
            <div class="form-group">
                <label for="role">권한 *</label>
                <select id="role" name="role" required>
                    <option value="">선택하세요</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>일반 관리자</option>
                    <option value="super_admin" {{ old('role') === 'super_admin' ? 'selected' : '' }}>최고 관리자</option>
                </select>
                @error('role')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}">
                @error('name')
                    <div class="error-message">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <button type="submit" class="btn-submit">계정 생성</button>
            </div>
        </form>
    </div>
@endsection

