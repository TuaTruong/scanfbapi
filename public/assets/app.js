// Ô "chọn tất cả" trong bảng: <input data-check-all="sel[]">
document.querySelectorAll('[data-check-all]').forEach(function (master) {
  master.addEventListener('change', function () {
    document.querySelectorAll('input[name="' + master.dataset.checkAll + '"]').forEach(function (box) {
      box.checked = master.checked;
    });
  });
});
