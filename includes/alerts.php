<?php
// includes/alerts.php
if (isset($_SESSION['success_message'])): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success!',
    text: '<?= addslashes($_SESSION['success_message']) ?>',
    confirmButtonColor: '#0d9488'
});
</script>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error!',
    text: '<?= addslashes($_SESSION['error_message']) ?>',
    confirmButtonColor: '#0d9488'
});
</script>
<?php endif; ?>