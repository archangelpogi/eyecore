<?php
// test-reservation.php - para sa debugging
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;

// Kung walang product_id, redirect or mag-error
if (!$product_id) {
    die('Please specify a product ID: test-reservation.php?id=231');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Reservation - Product <?php echo $product_id; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: Arial; padding: 20px; }
        .info { background: #f0f8ff; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>Test Reservation</h1>
    
    <div class="info">
        <strong>Testing Product ID:</strong> <?php echo $product_id; ?><br>
        <strong>Clinic ID:</strong> <?php echo $clinic_id ?: 'Auto-detect'; ?>
    </div>
    
    <form id="testForm">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
        <input type="hidden" name="clinic_id" value="<?php echo $clinic_id; ?>">
        <input type="hidden" name="color" value="" id="colorInput">
        <input type="hidden" name="quantity" value="1">
        <input type="hidden" name="date" value="<?php echo date('Y-m-d'); ?>">
        <input type="hidden" name="notes" value="Test reservation">
        
        <div style="margin-bottom: 15px;">
            <label>Select Color:</label>
            <select id="colorSelect" required style="margin-left: 10px; padding: 5px;">
                <option value="">-- Loading colors --</option>
            </select>
        </div>
        
        <button type="submit">Test Reserve</button>
    </form>

    <div id="result" style="margin-top: 20px; padding: 10px; border: 1px solid #ccc; white-space: pre-wrap; font-family: monospace;"></div>

    <script>
    // Load colors for this product
    fetch('api/get_product_colors.php?product_id=<?php echo $product_id; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.colors.length > 0) {
                let options = '';
                data.colors.forEach(color => {
                    options += `<option value="${color.color_code}" data-quantity="${color.quantity}">
                        ${color.color_name} (${color.color_code}) - ${color.quantity} available
                    </option>`;
                });
                document.getElementById('colorSelect').innerHTML = options;
                
                // Set default selected color
                document.getElementById('colorInput').value = data.colors[0].color_code;
            } else {
                document.getElementById('colorSelect').innerHTML = '<option value="">No colors available</option>';
            }
        });
    
    document.getElementById('colorSelect').addEventListener('change', function() {
        document.getElementById('colorInput').value = this.value;
    });
    
    document.getElementById('testForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!document.getElementById('colorInput').value) {
            Swal.fire('Error', 'Please select a color', 'error');
            return;
        }
        
        const formData = new FormData(this);
        
        fetch('process-reservation.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            document.getElementById('result').innerHTML = 
                '<h3>Raw Response:</h3><pre>' + text + '</pre>';
            
            try {
                const data = JSON.parse(text);
                document.getElementById('result').innerHTML += 
                    '<h3>Parsed JSON:</h3><pre>' + JSON.stringify(data, null, 2) + '</pre>';
                
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success');
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            } catch (e) {
                document.getElementById('result').innerHTML += 
                    '<h3 style="color:red">Invalid JSON! Error: ' + e.message + '</h3>';
            }
        })
        .catch(error => {
            document.getElementById('result').innerHTML = 'Fetch Error: ' + error;
        });
    });
    </script>
</body>
</html>