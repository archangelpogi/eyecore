    </main>
    </div>
</div>

<!-- footer.php -->
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Your custom scripts -->
    <script src="assets/js/main.js"></script>

    <!-- Support Chat Widget (Lalabas ito sa lahat maliban sa SuperAdmin) -->
    <?php 
    if (isset($_SESSION['role']) && $_SESSION['role'] !== 'SuperAdmin') {
        // I-check kung existing ang file bago i-include para iwas fatal error
        $widget_path = __DIR__ . '/../include/support_widget.php';
        if (file_exists($widget_path)) {
            include_once $widget_path;
        } else {
            // Optional: I-log kung sakaling mawala yung file
            // error_log("Support widget not found at: " . $widget_path);
        }
    }
    ?>

</body>
</html>