</div>
<footer class="container mt-5 mb-4">
  <hr />
  <small>Leningmanager</small>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const root = document.documentElement;
  const switchInput = document.getElementById('themeSwitch');

  // Load saved mode
  const saved = localStorage.getItem('theme') || 'dark';
  root.setAttribute('data-theme', saved);
  switchInput.checked = saved === 'dark';

  switchInput.addEventListener('change', () => {
    const mode = switchInput.checked ? 'dark' : 'light';
    root.setAttribute('data-theme', mode);
    localStorage.setItem('theme', mode);
  });
});
</script>
</body>
</html>