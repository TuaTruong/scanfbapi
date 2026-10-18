// Quét thông tin group (ScrapeCreators /v1/facebook/group) ngay trên trang Groups, không tải lại trang.
(function () {
  var csrfInput = document.querySelector('#groups-form input[name="csrf"]');
  if (!csrfInput) return;
  var csrf = csrfInput.value;
  var PARALLEL = 3;
  var running = false;
  var stopRequested = false;

  function fetchInfo(id) {
    var body = new FormData();
    body.append('csrf', csrf);
    body.append('id', id);
    return fetch('group-info.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () {
          return { ok: false, error: 'Phản hồi không hợp lệ (HTTP ' + res.status + ')' };
        });
      })
      .catch(function (err) {
        return { ok: false, error: 'Lỗi kết nối: ' + err.message };
      })
      .then(function (data) {
        apply(id, data);
        return data;
      });
  }

  function apply(id, data) {
    var summary = document.getElementById('info-summary-' + id);
    var detail = document.getElementById('info-detail-body-' + id);
    if (summary && data.summary !== undefined) summary.innerHTML = data.summary;
    else if (summary && data.error) summary.innerHTML = '<span class="text-danger">❌ ' + escapeHtml(data.error) + '</span>';
    if (detail && data.detail !== undefined) detail.innerHTML = data.detail;
    var input = document.querySelector('input[name="name[' + id + ']"]');
    if (input && !input.value && data.name) input.value = data.name;
    var row = document.querySelector('tr[data-group-id="' + id + '"]');
    if (row && data.ok) row.dataset.hasInfo = '1';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  document.querySelectorAll('.js-info-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var label = btn.textContent;
      btn.disabled = true;
      btn.textContent = '⏳ Đang quét…';
      fetchInfo(btn.dataset.id).then(function (data) {
        btn.disabled = false;
        btn.textContent = label;
        if (data.stop && data.error) alert(data.error);
      });
    });
  });

  var progress = document.getElementById('info-progress');
  var bar = progress.querySelector('.progress-bar');
  var text = progress.querySelector('.js-progress-text');
  progress.querySelector('.js-info-stop').addEventListener('click', function () {
    stopRequested = true;
    text.textContent += ' — đang dừng sau các yêu cầu đang chạy…';
  });

  document.querySelectorAll('.js-info-all').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (running) return;
      var all = btn.dataset.mode === 'all';
      var ids = Array.prototype.filter.call(document.querySelectorAll('tr[data-group-id]'), function (row) {
        return all || row.dataset.hasInfo !== '1';
      }).map(function (row) { return row.dataset.groupId; });
      if (!ids.length) {
        alert('Tất cả group đã có thông tin. Dùng “Quét lại thông tin tất cả” nếu muốn cập nhật.');
        return;
      }
      if (!confirm('Quét thông tin ' + ids.length + ' group?\nMỗi group tốn 1 credit (khoảng ' + ids.length + ' credit). Giữ trang này mở tới khi xong.')) return;

      running = true;
      stopRequested = false;
      var total = ids.length, done = 0, failed = 0;
      var buttons = document.querySelectorAll('.js-info-all, .js-info-one');
      buttons.forEach(function (b) { b.disabled = true; });
      progress.classList.remove('d-none');
      progress.querySelector('.js-info-stop').classList.remove('d-none');
      window.onbeforeunload = function () { return 'Đang quét thông tin group, rời trang sẽ dừng giữa chừng.'; };

      function update() {
        bar.style.width = Math.round(done * 100 / total) + '%';
        text.textContent = 'Đã quét ' + done + '/' + total + ' group' + (failed ? ' (' + failed + ' lỗi)' : '');
      }
      update();

      function worker() {
        if (stopRequested || !ids.length) return Promise.resolve();
        var id = ids.shift();
        return fetchInfo(id).then(function (data) {
          done++;
          if (!data.ok) failed++;
          if (data.stop) {
            stopRequested = true;
            if (data.error) alert(data.error);
          }
          update();
          return worker();
        });
      }

      var workers = [];
      for (var i = 0; i < PARALLEL; i++) workers.push(worker());
      Promise.all(workers).then(function () {
        running = false;
        window.onbeforeunload = null;
        buttons.forEach(function (b) { b.disabled = false; });
        text.textContent = (stopRequested && ids.length ? 'Đã dừng: ' : 'Xong: ') + 'quét ' + done + '/' + total + ' group'
          + (failed ? ', ' + failed + ' lỗi (xem dòng đỏ dưới tên group)' : '') + '. Tải lại trang để cập nhật số liệu.';
        progress.querySelector('.js-info-stop').classList.add('d-none');
      });
    });
  });
})();
