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
      setTimeout(() => f.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }), 0);
    });
  });

  // 처리 결과 토스트 자동 숨김
  const flash = document.querySelector('[data-flash]');
  if (flash) setTimeout(() => flash.remove(), 3500);
});
