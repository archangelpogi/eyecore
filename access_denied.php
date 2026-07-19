<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow text-center">
                    <div class="card-body p-5">
                        <div class="text-danger mb-4">
                            <i class="fas fa-ban fa-5x"></i>
                        </div>
                        <h2 class="text-danger mb-3">Access Denied</h2>
                        <p class="text-muted mb-4">
                            Your role <strong><?php echo htmlspecialchars($_SESSION['role'] ?? 'Unknown'); ?></strong> 
                            does not have access to this system.
                        </p>
                        
                        <div class="card bg-light mb-4">
                            <div class="card-body text-start">
                                <h6 class="card-title">User Information:</h6>
                                <p class="mb-1"><small>Name:</small> <?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?></p>
                                <p class="mb-1"><small>Email:</small> <?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                                <p class="mb-0"><small>Role:</small> <span class="badge bg-danger"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Unknown'); ?></span></p>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="admin/login.php" class="btn btn-primary">Go to Login</a>
                            <a href="javascript:history.back()" class="btn btn-outline-secondary">Go Back</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    Swal.fire({
        icon: 'error',
        title: 'Access Denied',
        text: 'Your role is not authorized to access this system.',
        confirmButtonColor: '#3085d6'
    });
    </script>
</body>
</html>