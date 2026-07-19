// Global variables
let currentFrame = null;
let currentPatient = null;
let rotation = 0;
let zoom = 100;
let frames = [];

// Load frames on page load
document.addEventListener('DOMContentLoaded', function() {
    loadFrames();
    setupEventListeners();
});

// Load frames from API
async function loadFrames() {
    try {
        const response = await fetch('api/framereview.php?action=get_frames');
        frames = await response.json();
        renderFrameList();
    } catch (error) {
        console.error('Error loading frames:', error);
        // Use sample data if API fails
        frames = [
            { id: 1, name: 'Ray-Ban Aviator Classic', color: 'Gold', price: 2500, category: 'Aviator' },
            { id: 2, name: 'Oakley Frogskins', color: 'Matte Black', price: 3200, category: 'Wayfarer' },
            { id: 3, name: 'Warby Parker Clark', color: 'Whiskey Tortoise', price: 1800, category: 'Round' },
            { id: 4, name: 'Ray-Ban Wayfarer', color: 'Black', price: 2800, category: 'Wayfarer' },
            { id: 5, name: 'Persol 649', color: 'Havana', price: 3500, category: 'Round' },
            { id: 6, name: 'Gucci GG0284S', color: 'Gold/Brown', price: 4200, category: 'Cat Eye' }
        ];
        renderFrameList();
    }
}

// Render frame list
function renderFrameList(filteredFrames = null) {
    const frameList = document.getElementById('frameList');
    const displayFrames = filteredFrames || frames;
    
    if (displayFrames.length === 0) {
        frameList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-eyeglasses display-6 mb-2"></i><br>
                No frames found
            </div>
        `;
        return;
    }
    
    frameList.innerHTML = displayFrames.map(frame => `
        <div class="frame-item card mb-2 p-2 ${currentFrame?.id === frame.id ? 'active' : ''}" 
             onclick="selectFrame(${frame.id})" 
             style="cursor: pointer;">
            <div class="d-flex align-items-center gap-2">
                <div style="font-size: 40px; color: ${getFrameColor(frame.color)}">🕶️</div>
                <div class="flex-fill">
                    <div class="fw-medium small">${frame.name}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">${frame.color} • ${frame.category}</div>
                    <div class="fw-medium small">₱${frame.price.toLocaleString()}</div>
                </div>
                <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); quickPreview(${frame.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
    `).join('');
    
    document.getElementById('frameCount').textContent = `${displayFrames.length} frames`;
    
    // Select first frame if none selected
    if (!currentFrame && displayFrames.length > 0) {
        selectFrame(displayFrames[0].id);
    }
}

// Select a frame
function selectFrame(frameId) {
    const frame = frames.find(f => f.id === frameId);
    if (!frame) return;
    
    currentFrame = frame;
    
    // Update UI
    document.getElementById('currentFrameName').textContent = frame.name;
    document.getElementById('currentFramePrice').textContent = `₱${frame.price.toLocaleString()}`;
    
    // Update preview
    updatePreview();
    
    // Highlight selected frame
    document.querySelectorAll('.frame-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`.frame-item[onclick*="${frameId}"]`)?.classList.add('active');
}

// Quick preview (without selecting)
function quickPreview(frameId) {
    const frame = frames.find(f => f.id === frameId);
    if (!frame) return;
    
    // Temporary preview
    const preview = document.getElementById('framePreview');
    preview.innerHTML = `
        <div style="font-size: 120px; color: #6c757d;">👤</div>
        <div style="font-size: 80px; margin-top: -80px; color: ${getFrameColor(frame.color)}">🕶️</div>
        <div class="mt-2 small text-muted">${frame.name}</div>
    `;
    
    setTimeout(() => {
        if (currentFrame) updatePreview();
    }, 2000);
}

// Update 3D preview
function updatePreview() {
    if (!currentFrame) return;
    
    const preview = document.getElementById('framePreview');
    const transform = `rotate(${rotation}deg) scale(${zoom / 100})`;
    
    preview.innerHTML = `
        <div style="font-size: 120px; color: #6c757d; transform: ${transform};">👤</div>
        <div style="font-size: 80px; margin-top: -80px; color: ${getFrameColor(currentFrame.color)}; transform: ${transform};">🕶️</div>
    `;
}

// Rotation controls
function rotateLeft() {
    rotation -= 15;
    updateRotationDisplay();
    updatePreview();
}

function rotateRight() {
    rotation += 15;
    updateRotationDisplay();
    updatePreview();
}

function resetRotation() {
    rotation = 0;
    updateRotationDisplay();
    updatePreview();
}

function updateRotationDisplay() {
    document.getElementById('rotationValue').textContent = `${rotation}°`;
}

// Zoom controls
function zoomOut() {
    zoom = Math.max(50, zoom - 10);
    updateZoomDisplay();
    updatePreview();
}

function zoomIn() {
    zoom = Math.min(200, zoom + 10);
    updateZoomDisplay();
    updatePreview();
}

function resetZoom() {
    zoom = 100;
    updateZoomDisplay();
    updatePreview();
}

function updateZoomDisplay() {
    document.getElementById('zoomValue').textContent = `${zoom}%`;
}

// Search frames
function searchFrames() {
    const searchTerm = document.getElementById('searchFrames').value.toLowerCase();
    const filtered = frames.filter(frame => 
        frame.name.toLowerCase().includes(searchTerm) ||
        frame.color.toLowerCase().includes(searchTerm) ||
        frame.category.toLowerCase().includes(searchTerm)
    );
    renderFrameList(filtered);
}

// Filter frames by category
function filterFrames() {
    const category = document.getElementById('filterCategory').value;
    const filtered = category ? frames.filter(frame => frame.category === category) : frames;
    renderFrameList(filtered);
}

// Load patient photo
function loadPatientPhoto() {
    const modal = new bootstrap.Modal(document.getElementById('photoUploadModal'));
    modal.show();
    
    // Preview photo when selected
    document.getElementById('patientPhoto').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photoPreview').innerHTML = `
                    <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 200px;">
                `;
            };
            reader.readAsDataURL(file);
        }
    });
}

// Upload photo
function uploadPhoto() {
    const fileInput = document.getElementById('patientPhoto');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('Please select a photo to upload.');
        return;
    }
    
    // In a real app, you would upload to server here
    const reader = new FileReader();
    reader.onload = function(e) {
        // Update preview with uploaded photo
        document.getElementById('framePreview').innerHTML = `
            <div class="position-relative">
                <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 300px;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(${rotation}deg) scale(${zoom / 100}); font-size: 80px; color: ${getFrameColor(currentFrame?.color || '#0891b2')}">🕶️</div>
            </div>
        `;
        
        bootstrap.Modal.getInstance(document.getElementById('photoUploadModal')).hide();
    };
    reader.readAsDataURL(file);
}

// Select patient
function selectPatient() {
    loadPatientList();
    const modal = new bootstrap.Modal(document.getElementById('patientSelectModal'));
    modal.show();
}

// Load patient list
async function loadPatientList() {
    try {
        const response = await fetch('api/framereview.php?action=get_patients');
        const patients = await response.json();
        
        const patientList = document.getElementById('patientList');
        patientList.innerHTML = patients.map(patient => `
            <button class="list-group-item list-group-item-action" onclick="choosePatient(${patient.id}, '${patient.first_name} ${patient.last_name}', '${patient.patient_id}')">
                <div class="fw-medium">${patient.first_name} ${patient.last_name}</div>
                <small class="text-muted">ID: ${patient.patient_id} • ${patient.age || 'N/A'} years old</small>
            </button>
        `).join('');
        
        // Setup search
        document.getElementById('searchPatientInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const items = patientList.querySelectorAll('.list-group-item');
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(searchTerm) ? 'block' : 'none';
            });
        });
        
    } catch (error) {
        console.error('Error loading patients:', error);
        document.getElementById('patientList').innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-exclamation-triangle display-6 mb-2"></i><br>
                Failed to load patients
            </div>
        `;
    }
}

// Choose patient
function choosePatient(patientId, patientName, patientCode) {
    currentPatient = { id: patientId, name: patientName, code: patientCode };
    
    // Update UI
    document.getElementById('patientInfo').classList.remove('d-none');
    document.getElementById('selectedPatientName').textContent = patientName;
    document.getElementById('selectedPatientId').textContent = patientCode;
    
    bootstrap.Modal.getInstance(document.getElementById('patientSelectModal')).hide();
}

// Clear patient selection
function clearPatient() {
    currentPatient = null;
    document.getElementById('patientInfo').classList.add('d-none');
}

// Take screenshot
function takeScreenshot() {
    const preview = document.getElementById('framePreview');
    
    // Use html2canvas for actual screenshot in production
    // For demo, create a simulated screenshot
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = 800;
    canvas.height = 600;
    
    // Draw simulated screenshot
    ctx.fillStyle = '#f8f9fa';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Draw frame
    ctx.font = '40px Arial';
    ctx.fillStyle = getFrameColor(currentFrame?.color || '#0891b2');
    ctx.fillText('🕶️', 350, 300);
    
    // Add info text
    ctx.fillStyle = '#000';
    ctx.font = '20px Arial';
    ctx.fillText(currentFrame?.name || 'No frame selected', 300, 400);
    ctx.fillText(`Patient: ${currentPatient?.name || 'Not selected'}`, 300, 430);
    ctx.fillText(new Date().toLocaleString(), 300, 460);
    
    // Show preview
    const screenshotImage = document.getElementById('screenshotImage');
    screenshotImage.src = canvas.toDataURL('image/png');
    
    const modal = new bootstrap.Modal(document.getElementById('screenshotModal'));
    modal.show();
    
    // Copy to clipboard (simulated)
    canvas.toBlob(blob => {
        navigator.clipboard.write([
            new ClipboardItem({ 'image/png': blob })
        ]).then(() => {
            console.log('Screenshot copied to clipboard');
        });
    });
}

// Download screenshot
function downloadScreenshot() {
    const link = document.createElement('a');
    link.download = `frame_preview_${new Date().toISOString().slice(0,10)}.png`;
    link.href = document.getElementById('screenshotImage').src;
    link.click();
}

// Save to patient record
async function saveToPatientRecord() {
    if (!currentPatient) {
        alert('Please select a patient first.');
        return;
    }
    
    if (!currentFrame) {
        alert('Please select a frame first.');
        return;
    }
    
    const confirmation = confirm(`Save frame "${currentFrame.name}" to ${currentPatient.name}'s record?`);
    if (!confirmation) return;
    
    try {
        const response = await fetch('api/framereview.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_frame',
                patient_id: currentPatient.id,
                frame_id: currentFrame.id,
                frame_name: currentFrame.name,
                notes: 'Saved from 3D Frame Review'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Frame saved to patient record successfully!');
        } else {
            alert('Failed to save frame: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Error saving frame. Please try again.');
        console.error('Save error:', error);
    }
}

// Helper: Get frame color
function getFrameColor(colorName) {
    const colors = {
        'Gold': '#FFD700',
        'Matte Black': '#333',
        'Whiskey Tortoise': '#8B4513',
        'Black': '#000',
        'Havana': '#6F4E37',
        'Gold/Brown': '#B8860B'
    };
    return colors[colorName] || '#0891b2';
}

// Setup event listeners
function setupEventListeners() {
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') rotateLeft();
        if (e.key === 'ArrowRight') rotateRight();
        if (e.key === '-') zoomOut();
        if (e.key === '+' || e.key === '=') zoomIn();
        if (e.key === 'r') resetRotation();
        if (e.key === 'z') resetZoom();
        if (e.key === 's' && e.ctrlKey) {
            e.preventDefault();
            takeScreenshot();
        }
    });
}