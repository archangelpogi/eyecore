<?php
// Only HTML here, no JavaScript
?>
<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h3 class="fw-bold">3D Frame Review</h3>
            <p class="text-muted mb-0">Virtual frame fitting and preview</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" onclick="loadPatientPhoto()">
                <i class="bi bi-person-circle me-2"></i>Load Patient Photo
            </button>
            <button class="btn btn-primary" onclick="selectPatient()">
                <i class="bi bi-people me-2"></i>Select Patient
            </button>
        </div>
    </div>

    <!-- Patient Info (if selected) -->
    <div class="alert alert-info d-none" id="patientInfo">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <strong>Selected Patient:</strong> 
                <span id="selectedPatientName">Loading...</span>
                <small class="text-muted ms-2">ID: <span id="selectedPatientId"></span></small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearPatient()">
                <i class="bi bi-x"></i> Clear
            </button>
        </div>
    </div>

    <div class="row g-3">
        <!-- Left Column: 3D Viewer -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Virtual Try-On Viewer</h5>
                    <div>
                        <span class="badge bg-primary me-2" id="currentFrameName">Ray-Ban Aviator Classic</span>
                        <span class="badge bg-secondary" id="currentFramePrice">₱2,500</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- 3D Viewer Container -->
                    <div class="viewer-3d mb-4">
                        <div id="framePreview" class="text-center">
                            <div style="font-size: 120px; color: #6c757d;">👤</div>
                            <div style="font-size: 80px; margin-top: -80px; color: #0891b2;">🕶️</div>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Rotation</label>
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-secondary" onclick="rotateLeft()">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button class="btn btn-outline-secondary flex-fill" id="rotationValue">0°</button>
                                <button class="btn btn-outline-secondary" onclick="rotateRight()">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="resetRotation()">Reset</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Zoom</label>
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-secondary" onclick="zoomOut()">
                                    <i class="bi bi-zoom-out"></i>
                                </button>
                                <button class="btn btn-outline-secondary flex-fill" id="zoomValue">100%</button>
                                <button class="btn btn-outline-secondary" onclick="zoomIn()">
                                    <i class="bi bi-zoom-in"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="resetZoom()">Reset</button>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="takeScreenshot()">
                            <i class="bi bi-camera me-2"></i>Take Screenshot
                        </button>
                        <button class="btn btn-outline-primary" onclick="saveToPatientRecord()">
                            <i class="bi bi-save me-2"></i>Save to Patient Record
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Frame Selector -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Frame Selector</h5>
                        <small class="text-muted" id="frameCount">3 frames</small>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Search -->
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" placeholder="Search frames..." id="searchFrames" onkeyup="searchFrames()">
                    </div>
                    
                    <!-- Filter -->
                    <div class="mb-3">
                        <select class="form-select form-select-sm" id="filterCategory" onchange="filterFrames()">
                            <option value="">All Categories</option>
                            <option value="Aviator">Aviator</option>
                            <option value="Wayfarer">Wayfarer</option>
                            <option value="Round">Round</option>
                            <option value="Cat Eye">Cat Eye</option>
                        </select>
                    </div>
                    
                    <!-- Frame List -->
                    <div id="frameList" class="frame-list">
                        <!-- Frames will be loaded by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Patient Selection Modal -->
<div class="modal fade" id="patientSelectModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" placeholder="Search patients..." id="searchPatientInput">
                <div class="list-group" id="patientList">
                    <!-- Patients will be loaded by JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Photo Upload Modal -->
<div class="modal fade" id="photoUploadModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Patient Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Upload Photo</label>
                    <input type="file" class="form-control" id="patientPhoto" accept="image/*">
                </div>
                <div class="text-center">
                    <div class="border rounded p-4 mb-3">
                        <div id="photoPreview" class="text-muted">
                            <i class="bi bi-image display-6"></i><br>
                            <small>No photo selected</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="uploadPhoto()">Upload Photo</button>
            </div>
        </div>
    </div>
</div>

<!-- Screenshot Preview Modal -->
<div class="modal fade" id="screenshotModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Screenshot Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="screenshotImage" src="" class="img-fluid mb-3" style="max-height: 400px;">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Screenshot saved to clipboard. You can also right-click to save.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" onclick="downloadScreenshot()">
                    <i class="bi bi-download me-2"></i>Download
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/frame-review.js"></script>