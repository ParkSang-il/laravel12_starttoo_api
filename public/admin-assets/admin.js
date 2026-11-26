// STARTTOO 운영 콘솔 · 최소 동작 스크립트 (프레임워크 없음)
document.addEventListener('DOMContentLoaded', () => {
  // 콘텐츠 일괄 선택 바
  const form = document.querySelector('[data-bulk-form]');
  if (form) {
    const bar = form.querySelector('[data-bulk-bar]');
    const count = form.querySelector('[data-bulk-count]');
    const update = () => {
      if (!bar) return;
      const n = form.querySelectorAll('[data-bulk-check]:checked').length;
      bar.hidden = n === 0;
      count.textContent = n + '개 선택됨';
    };
    form.addEventListener('change', update);
    update();
  }

  // 되돌리기 어려운 작업 확인
  document.querySelectorAll('[data-confirm]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
    });
  });

  // 같은 처리를 두 번 보내지 않게: 제출하면 그 폼의 버튼을 잠근다
  document.querySelectorAll('form[method="POST"], form[method="post"]').forEach((f) => {
    f.addEventListener('submit', () => {
      setTimeout(() => {
        f.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; });
        // 폼 밖에서 form="아이디" 로 연결한 버튼도
        if (f.id) document.querySelectorAll('button[form="' + f.id + '"]').forEach((b) => { b.disabled = true; });
      }, 0);
    });
  });

  // 푸시 미리보기: 제목 · 내용을 입력하는 대로 잠금 화면 카드에 보여 준다
  document.querySelectorAll('[data-preview]').forEach((input) => {
    const key = input.dataset.preview;
    const out = document.querySelector('[data-preview-out="' + key + '"]');
    if (!out) return;
    // 앞에 붙는 글자(광고성: 「(광고) 」 · 「STARTTOO 」, 정보성: 없음)는 화면이 data-prefix 로 알려 준다
    const prefix = out.dataset.prefix || '';
    const fallback = key === 'title' ? '제목' : '내용';
    const update = () => { out.textContent = prefix + (input.value.trim() || fallback); };
    input.addEventListener('input', update);
    input.addEventListener('change', update);
    update();
  });

  // 처리 결과 토스트 자동 숨김
  const flash = document.querySelector('[data-flash]');
  if (flash) setTimeout(() => flash.remove(), 3500);
});
