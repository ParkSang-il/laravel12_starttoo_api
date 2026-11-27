<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 외부 광고 영업(디자인 AW-01 ~ AW-03, docs/ads.md 「외부 광고」).
 * 흐름: 광고 문의(웹) → 운영 콘솔 견적(CPM) → 광고주 수락 · 입금 알림 → 입금 확인(계약) → 광고 만들기 → 노출 · 성과 보고 → 종료
 * - advertisers: 광고주 계정(이메일 + 비밀번호). 견적 메일 링크(이메일 소유 확인)에서만 비밀번호를 만든다.
 * - advertiser_tokens: 비밀번호 설정 · 재설정 메일 링크(해시만 저장, 1회용)
 * - ad_inquiries / ad_contracts: 문의 · 견적 → 계약
 * - starttoo_ads.contract_id · impression_cap: 계약 광고 연결 · CPM 보장 노출을 채우면 그만 보여 준다
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_advertisers', function (Blueprint $t) {
            $t->id();
            $t->string('email', 150)->unique()->comment('소문자로 저장');
            $t->string('password')->nullable()->comment('비밀번호를 만들기 전에는 null(로그인 불가)');
            $t->string('company', 100);
            $t->string('contact_name', 60)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('biz_no', 20)->nullable()->comment('세금계산서 정보(다음 견적의 입금 칸에 미리 채움)');
            $t->string('biz_name', 120)->nullable();
            $t->string('tax_email', 150)->nullable();
            $t->unsignedTinyInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('password_set_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });

        Schema::create('starttoo_advertiser_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('advertiser_id');
            $t->string('token_hash', 64)->unique()->comment('sha256(토큰)');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('advertiser_id');
        });

        Schema::create('starttoo_ad_inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('code', 24)->unique()->comment('AD-20261127-014');
            $t->unsignedBigInteger('advertiser_id')->nullable();
            $t->string('company', 100);
            $t->string('contact_name', 60);
            $t->string('email', 150);
            $t->string('phone', 30);
            $t->string('industry', 40)->nullable();
            $t->json('slots')->comment('["feed_inline","community_top"]');
            $t->date('wish_start')->nullable();
            $t->string('duration', 20)->nullable();
            $t->string('budget', 20)->nullable();
            $t->string('landing_url', 500)->nullable();
            $t->json('files')->nullable()->comment('[{path, name, size}] 비공개 디스크');
            $t->text('memo')->nullable();
            $t->timestamp('privacy_agreed_at');
            $t->string('status', 20)->default('new')->comment('new · quoted · contracted · rejected · closed');
            $t->string('reject_reason', 500)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index('advertiser_id');
        });

        Schema::create('starttoo_ad_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('inquiry_id')->nullable();
            $t->unsignedBigInteger('advertiser_id')->nullable();
            $t->string('quote_no', 24)->unique()->comment('Q-2026-0042');
            $t->string('title', 120);
            $t->string('company', 100);
            $t->string('contact_name', 60);
            $t->string('email', 150);
            $t->string('manager', 60)->nullable()->comment('운영 담당자');
            $t->string('landing_url', 500)->nullable();
            $t->json('lines')->comment('[{slot, imps, cpm, amount}] CPM 보장 노출');
            $t->string('discount_label', 60)->nullable();
            $t->unsignedInteger('discount')->default(0);
            $t->unsignedInteger('supply');
            $t->unsignedInteger('vat');
            $t->unsignedInteger('total');
            $t->json('terms')->nullable();
            $t->date('valid_until');
            $t->date('starts_at');
            $t->date('ends_at')->comment('보장 노출을 다 못 채워도 이 날까지만 노출');
            $t->string('status', 24)->default('quote_sent')
                ->comment('quote_sent · awaiting_payment · payment_reported · contracted · live · ended · cancelled');
            $t->text('change_request')->nullable();
            $t->timestamp('change_requested_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('payment_reported_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('tax_invoice_issued_at')->nullable();
            $t->timestamp('report_mailed_at')->nullable()->comment('노출 시작 성과 보고 메일');
            $t->timestamp('final_mailed_at')->nullable()->comment('종료 최종 보고 메일');
            $t->string('biz_no', 20)->nullable();
            $t->string('biz_name', 120)->nullable();
            $t->string('tax_email', 150)->nullable();
            $t->string('biz_cert_path', 300)->nullable();
            $t->string('quote_token', 64)->unique();
            $t->string('report_token', 64)->nullable()->unique();
            $t->text('memo')->nullable()->comment('운영 메모(광고주에게 안 보임)');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'updated_at']);
            $t->index('advertiser_id');
            $t->index('inquiry_id');
        });

        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->unsignedBigInteger('contract_id')->nullable()->after('id')->comment('외부 광고 계약');
            $t->unsignedInteger('impression_cap')->nullable()->comment('CPM 보장 노출. impressions 가 이 값에 닿으면 그만 보여 준다');
            $t->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropIndex(['contract_id']);
            $t->dropColumn(['contract_id', 'impression_cap']);
        });
        Schema::dropIfExists('starttoo_ad_contracts');
        Schema::dropIfExists('starttoo_ad_inquiries');
        Schema::dropIfExists('starttoo_advertiser_tokens');
        Schema::dropIfExists('starttoo_advertisers');
    }
};
