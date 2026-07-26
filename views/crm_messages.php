<?php


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check
if (!RBACHelper::hasPermission('crm_messages_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to view messages.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$userName = $_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '');
$userRole = $_SESSION['role'] ?? '';

// ✅ Get user permissions for UI
$canSend = RBACHelper::hasPermission('crm_messages_create');
$canDelete = RBACHelper::hasPermission('crm_messages_delete');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #ccfbf1;
            --teal-soft: #f0fdfa;
        }
        
        body { background: #f4f6fb; }
        
        .card-messages {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
            height: calc(100vh - 140px);
            min-height: 560px;
            display: flex;
            flex-direction: column;
        }
        
        .card-messages .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            flex-shrink: 0;
        }

        .card-messages .card-body {
            flex: 1;
            overflow: hidden;
            min-height: 0;
        }

        .card-messages .card-body .row.g-0 {
            height: 100%;
        }

        /* Left column (conversations) — fixed height, only the list scrolls */
        .conversations-col {
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .conversations-col .p-3.border-bottom {
            flex-shrink: 0;
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        /* Right column (chat) — fixed height, only the messages scroll */
        .chat-col {
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        
        .conversation-item {
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }
        
        .conversation-item:hover {
            background-color: #f8fafc;
        }
        
        .conversation-item.active {
            background-color: var(--teal-soft);
            border-left-color: var(--teal);
        }
        
        .conversation-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .chat-area {
            height: calc(100vh - 200px);
            min-height: 500px;
            display: flex;
            flex-direction: column;
            background: #f8fafc;
        }
        
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            min-height: 0;
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            position: relative;
        }
        
        .message-outgoing {
            background: var(--teal);
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }
        
        .message-incoming {
            background: white;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            border-bottom-left-radius: 4px;
        }
        
        .message-time {
            font-size: 0.65rem;
            opacity: 0.7;
            margin-top: 4px;
        }
        
        .message-sender {
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .chat-input-area {
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            flex-shrink: 0;
        }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-teal:hover {
            background: var(--teal-dark);
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-outline-teal {
            border: 1px solid var(--teal);
            color: var(--teal);
            background: transparent;
            border-radius: 12px;
        }
        
        .btn-outline-teal:hover {
            background: var(--teal-soft);
            color: var(--teal-dark);
        }
        
        .badge-unread {
            background: var(--teal);
            color: white;
            border-radius: 20px;
            font-size: 0.7rem;
            padding: 0.25rem 0.6rem;
        }
        
        .chat-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            flex-shrink: 0;
        }
        
        .search-input {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1rem;
        }
        
        .search-input:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,148,136,0.1);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 4rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }

        .message-bubble {
    max-width: 70%;
    padding: 0.75rem 1rem;
    border-radius: 18px;
    position: relative;
    word-wrap: break-word;
}

.message-outgoing {
    background: var(--teal);
    color: white;
    border-bottom-right-radius: 4px;
    margin-left: auto;
}

.message-incoming {
    background: white;
    border: 1px solid #e2e8f0;
    color: #1e293b;
    border-bottom-left-radius: 4px;
    margin-right: auto;
}

.message-sender {
    font-size: 0.7rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.message-text {
    font-size: 0.9rem;
    line-height: 1.4;
    margin-bottom: 4px;
}

.message-time {
    font-size: 0.65rem;
    opacity: 0.7;
}

.text-teal {
    color: var(--teal) !important;
}

/* ===== IMAGE MESSAGE ===== */
.message-bubble.has-image {
    padding: 6px;
    background: transparent !important;
    border: none !important;
}
.message-bubble.has-image.message-outgoing .message-sender,
.message-bubble.has-image.message-incoming .message-sender {
    padding: 0 6px;
}
.chat-img {
    max-width: 220px;
    max-height: 220px;
    border-radius: 14px;
    display: block;
    cursor: pointer;
    object-fit: cover;
}
.message-outgoing .chat-img { border: 2px solid var(--teal-light); }
.message-incoming .chat-img { border: 1px solid #e2e8f0; }
.chat-img-caption {
    padding: 8px 6px 2px;
    font-size: 0.85rem;
}

/* ===== IMAGE PREVIEW BEFORE SENDING ===== */
.image-preview-bar {
    display: none;
    align-items: center;
    gap: 10px;
    padding: 0.6rem 0;
}
.image-preview-bar.show { display: flex; }
.image-preview-thumb {
    position: relative;
    width: 52px;
    height: 52px;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    border: 1px solid #e2e8f0;
}
.image-preview-thumb img { width: 100%; height: 100%; object-fit: cover; }
.image-preview-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 20px;
    height: 20px;
    background: #ef4444;
    color: #fff;
    border: 2px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    cursor: pointer;
}
.image-preview-label { font-size: 0.8rem; color: #64748b; }

.attach-img-btn {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}
.attach-img-btn:hover {
    background: var(--teal-soft);
    color: var(--teal-dark);
    border-color: var(--teal);
}

/* ===== LIGHTBOX ===== */
.img-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.85);
    z-index: 5000;
    align-items: center;
    justify-content: center;
    padding: 30px;
}
.img-lightbox.show { display: flex; }
.img-lightbox img { max-width: 90%; max-height: 90%; border-radius: 8px; }
.img-lightbox-close {
    position: absolute;
    top: 20px;
    right: 24px;
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,.15);
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">
    <div class="row">
        <div class="col-12">
            <div class="card-messages card shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h4 class="mb-0 fw-bold" style="color: var(--teal);">
                                <i class="bi bi-chat-dots me-2"></i>Messages
                            </h4>
                            <p class="text-muted small mb-0 mt-1">Manage conversations with patients and staff</p>
                        </div>
                        <?php if ($canSend): ?>
                        <button class="btn btn-teal btn-sm px-3" data-bs-toggle="modal" data-bs-target="#newMessageModal" onclick="resetNewMessageModal()">
                            <i class="bi bi-pencil-square me-1"></i> New Message
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="row g-0">
                        <!-- Left Side - Conversations -->
                        <div class="col-md-4 border-end conversations-col" style="background: white;">
                            <div class="p-3 border-bottom">
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent border-end-0" style="border-radius: 12px 0 0 12px;">
                                        <i class="bi bi-search text-muted"></i>
                                    </span>
                                    <input type="text" id="searchConversation" class="form-control search-input border-start-0 ps-0" 
                                           placeholder="Search conversations..." style="border-radius: 0 12px 12px 0;">
                                </div>
                            </div>
                            <div id="conversationsList" class="conversations-list">
                                <div class="empty-state">
                                    <div class="spinner-border text-teal"></div>
                                    <p class="mt-2">Loading conversations...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Right Side - Chat -->
                        <div class="col-md-8 chat-col" style="background: #f8fafc;">
                            <div id="chatHeader" class="chat-header d-none">
                                <div class="d-flex align-items-center">
                                    <div class="conversation-avatar me-3" id="chatAvatar">
                                        <i class="bi bi-person"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold" id="chatWithName"></h6>
                                        <small class="text-muted" id="chatWithStatus"></small>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="messagesArea" class="messages-container">
                                <div class="empty-state">
                                    <i class="bi bi-chat-dots"></i>
                                    <p class="mt-2">Select a conversation to start messaging</p>
                                </div>
                            </div>

                            <?php if ($canSend): ?>
                            <div id="messageInputArea" class="chat-input-area d-none">
                                <!-- Image preview before sending -->
                                <div class="image-preview-bar" id="imagePreviewBar">
                                    <div class="image-preview-thumb">
                                        <img id="imagePreviewThumb" src="" alt="">
                                        <div class="image-preview-remove" onclick="removeSelectedImage()"><i class="bi bi-x"></i></div>
                                    </div>
                                    <div class="image-preview-label">Image ready to send</div>
                                </div>
                                <form id="sendMessageForm" onsubmit="sendMessage(event)">
                                    <div class="input-group">
                                        <input type="file" id="chatImageInput" accept="image/*" style="display:none" onchange="handleImageSelect(event)">
                                        <button class="btn attach-img-btn" type="button" onclick="document.getElementById('chatImageInput').click()" title="Attach an image">
                                            <i class="bi bi-image"></i>
                                        </button>
                                        <input type="text" id="messageText" class="form-control search-input" 
                                               placeholder="Type your message...">
                                        <button class="btn btn-teal" type="submit" style="border-radius: 12px;">
                                            <i class="bi bi-send"></i> Send
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php else: ?>
                            <div id="messageInputArea" class="chat-input-area d-none">
                                <div class="alert alert-warning mb-0 text-center">
                                    <i class="bi bi-lock me-2"></i>You don't have permission to send messages.
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Message Modal -->
<div class="modal fade" id="newMessageModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>New Message
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="closeNewMessageModal()"></button>
            </div>
            <div class="modal-body p-4">
                <form id="newMessageForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Send To</label>
                        <select id="receiver_type" class="form-select mb-2" style="border-radius: 12px;">
                            <option value="user">Staff Member</option>
                            <option value="patient">Patient</option>
                        </select>
                        <select id="receiver_id" class="form-select" style="border-radius: 12px;" required>
                            <option value="">Select...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message</label>
                        <textarea id="new_message" class="form-control" rows="4" 
                                  style="border-radius: 12px;" placeholder="Type your message here..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Image (optional)</label>
                        <input type="file" id="new_message_image" accept="image/*" class="form-control" style="border-radius: 12px;" onchange="handleNewMsgImageSelect(event)">
                        <div class="image-preview-bar mt-2" id="newMsgImagePreviewBar">
                            <div class="image-preview-thumb">
                                <img id="newMsgImagePreviewThumb" src="" alt="">
                                <div class="image-preview-remove" onclick="removeNewMsgImage()"><i class="bi bi-x"></i></div>
                            </div>
                            <div class="image-preview-label">Image ready to send</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-outline-secondary px-4" style="border-radius: 12px;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-teal px-4" onclick="sendNewMessage()">
                    <i class="bi bi-send me-1"></i> Send Message
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen image viewer -->
<div class="img-lightbox" id="imgLightbox" onclick="closeLightbox(event)">
    <button class="img-lightbox-close" onclick="closeLightbox(event)"><i class="bi bi-x-lg"></i></button>
    <img id="imgLightboxImg" src="" alt="">
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const clinicId = <?= $clinicId ?>;
const userId = <?= $userId ?>;
const userRole = '<?= $userRole ?>';
const canSend = <?= $canSend ? 'true' : 'false' ?>;
let currentConversation = null;
let currentUser = null;
let selectedChatImage = null;
let selectedNewMsgImage = null;

$(document).ready(function() {
    loadConversations();
    loadRecipients();
    
    $('#searchConversation').on('keyup', function() {
        filterConversations($(this).val());
    });
    
    setInterval(function() {
        if (currentConversation) {
            loadMessages(currentConversation);
        }
        loadConversations();
    }, 5000);
});

function resetNewMessageModal() {
    $('#newMessageForm')[0].reset();
    removeNewMsgImage();
    loadRecipients();
    // Remove any existing backdrops
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
}

function closeNewMessageModal() {
    const modalElement = document.getElementById('newMessageModal');
    const modal = bootstrap.Modal.getInstance(modalElement);
    if (modal) {
        modal.hide();
    }
    setTimeout(function() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(function(backdrop) {
            backdrop.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
    }, 100);
}

function loadRecipients() {
    const type = $('#receiver_type').val();
    const receiverSelect = $('#receiver_id');
    
    receiverSelect.html('<option value="">Loading...</option>');
    
    $.ajax({
        url: 'api/crm_messages.php?action=get_recipients',
        method: 'GET',
        data: { type: type },
        success: function(data) {
            let options = '<option value="">Select...</option>';
            if (data && data.length > 0) {
                data.forEach(r => {
                    options += `<option value="${r.id}">${escapeHtml(r.name)}</option>`;
                });
            } else {
                options = '<option value="">No recipients found</option>';
            }
            receiverSelect.html(options);
        },
        error: function() {
            receiverSelect.html('<option value="">Error loading recipients</option>');
        }
    });
}

$('#receiver_type').change(loadRecipients);

function loadConversations() {
    $.ajax({
        url: 'api/crm_messages.php?action=get_conversations',
        method: 'GET',
        success: function(data) {
            let html = '';
            
            if (data && Array.isArray(data) && data.length > 0) {
                data.forEach(c => {
                    const active = currentConversation === c.conversation_id ? 'active' : '';
                    const unreadBadge = c.unread > 0 ? `<span class="badge-unread ms-auto">${c.unread}</span>` : '';
                    const lastTime = c.last_time ? `<small class="text-muted">${c.last_time}</small>` : '';
                    const lastMsg = c.last_message ? `<small class="text-truncate d-block text-muted" style="max-width: 180px;">${escapeHtml(c.last_message)}</small>` : '<small class="text-truncate d-block text-muted">No messages yet</small>';
                    
                    html += `
                        <div class="conversation-item p-3 ${active}" onclick="openConversation('${c.conversation_id}', '${escapeHtml(c.other_name)}', '${c.other_type}', '${c.other_avatar || ''}')">
                            <div class="d-flex align-items-center">
                                <div class="conversation-avatar flex-shrink-0">
                                    <i class="bi bi-person fs-4"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="fw-semibold">${escapeHtml(c.other_name)}</strong>
                                        ${lastTime}
                                    </div>
                                    ${lastMsg}
                                </div>
                                ${unreadBadge}
                            </div>
                        </div>
                    `;
                });
            } else {
                html = `
                    <div class="empty-state">
                        <i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>
                        <p class="mb-0 fw-semibold">No conversations yet</p>
                        <small class="text-muted">Start a new message to connect with patients or staff</small>
                        ${canSend ? `
                        <div class="mt-3">
                            <button class="btn btn-teal btn-sm" data-bs-toggle="modal" data-bs-target="#newMessageModal" onclick="resetNewMessageModal()">
                                <i class="bi bi-pencil-square me-1"></i> Send First Message
                            </button>
                        </div>
                        ` : ''}
                    </div>
                `;
            }
            $('#conversationsList').html(html);
        },
        error: function(xhr, status, error) {
            console.error('Error loading conversations:', error);
            $('#conversationsList').html(`
                <div class="empty-state">
                    <i class="bi bi-exclamation-triangle fs-1 d-block mb-3 text-danger opacity-50"></i>
                    <p class="mb-0 fw-semibold">Failed to load conversations</p>
                    <small class="text-muted">Please refresh the page</small>
                    <div class="mt-3">
                        <button class="btn btn-outline-teal btn-sm" onclick="loadConversations()">
                            <i class="bi bi-arrow-repeat me-1"></i> Retry
                        </button>
                    </div>
                </div>
            `);
        }
    });
}

function filterConversations(search) {
    $('#conversationsList .conversation-item').each(function() {
        const text = $(this).text().toLowerCase();
        $(this).toggle(text.includes(search.toLowerCase()));
    });
}

function openConversation(convId, otherName, otherType, avatar) {
    currentConversation = convId;
    currentUser = { name: otherName, type: otherType, avatar: avatar };
    
    $('#chatHeader').removeClass('d-none');
    $('#chatAvatar').html(avatar ? `<img src="${avatar}" class="w-100 h-100 rounded-3 object-fit-cover">` : `<i class="bi bi-person fs-4"></i>`);
    $('#chatWithName').text(otherName);
    $('#chatWithStatus').text(otherType === 'user' ? 'Staff Member' : 'Patient');
    
    if (canSend) {
        $('#messageInputArea').removeClass('d-none').show();
    }
    
    removeSelectedImage();
    loadMessages(convId);
}

function loadMessages(convId) {
    $.ajax({
        url: 'api/crm_messages.php?action=get_messages',
        method: 'GET',
        data: { conversation_id: convId },
        success: function(data) {
            let html = '';
            if (data && data.length > 0) {
                data.forEach(m => {
                    // Determine if message is from clinic (outgoing) or from user (incoming)
                    // m.sender_type = 'clinic' means clinic staff sent it (OUTGOING)
                    // m.sender_type = 'user' means patient sent it (INCOMING)
                    const isOutgoing = (m.sender_type === 'clinic');
                    const side = isOutgoing ? 'justify-content-end' : 'justify-content-start';
                    const bubbleClass = isOutgoing ? 'message-outgoing' : 'message-incoming';
                    const senderClass = isOutgoing ? 'text-white-50' : 'text-teal';
                    const timeClass = isOutgoing ? 'text-white-50 text-end' : 'text-muted';

                    if (m.image) {
                        // IMAGE MESSAGE (may caption man o wala)
                        const captionHtml = m.message ? `<div class="chat-img-caption">${escapeHtml(m.message)}</div>` : '';
                        html += `
                            <div class="d-flex ${side} mb-2">
                                <div class="message-bubble has-image ${bubbleClass}">
                                    <div class="message-sender ${senderClass}" style="padding:6px 6px 0">${escapeHtml(m.sender_name)}</div>
                                    <img class="chat-img" src="${m.image}" alt="Sent image" onclick="openLightbox('${m.image}')">
                                    ${captionHtml}
                                    <div class="message-time ${timeClass}" style="padding:0 6px 4px">${m.time}</div>
                                </div>
                            </div>
                        `;
                    } else if (isOutgoing) {
                        // OUTGOING MESSAGE - CLINIC STAFF (RIGHT SIDE)
                        html += `
                            <div class="d-flex justify-content-end mb-2">
                                <div class="message-bubble message-outgoing">
                                    <div class="message-sender text-white-50">${escapeHtml(m.sender_name)}</div>
                                    <div class="message-text">${escapeHtml(m.message)}</div>
                                    <div class="message-time text-white-50 text-end">${m.time}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        // INCOMING MESSAGE - PATIENT (LEFT SIDE)
                        html += `
                            <div class="d-flex justify-content-start mb-2">
                                <div class="message-bubble message-incoming">
                                    <div class="message-sender text-teal">${escapeHtml(m.sender_name)}</div>
                                    <div class="message-text">${escapeHtml(m.message)}</div>
                                    <div class="message-time text-muted">${m.time}</div>
                                </div>
                            </div>
                        `;
                    }
                });
            } else {
                html = `
                    <div class="empty-state">
                        <i class="bi bi-chat-dots"></i>
                        <p class="mt-2">No messages yet</p>
                        <small class="text-muted">Send a message to start the conversation</small>
                    </div>
                `;
            }
            $('#messagesArea').html(html);
            $('#messagesArea').scrollTop($('#messagesArea')[0].scrollHeight);
        },
        error: function() {
            $('#messagesArea').html(`
                <div class="empty-state">
                    <i class="bi bi-exclamation-triangle text-danger"></i>
                    <p class="mt-2">Failed to load messages</p>
                    <small class="text-muted">Please try again</small>
                </div>
            `);
        }
    });
}

// ── IMAGE SELECT (reply box) ──
function handleImageSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        alert('Please choose an image file.');
        event.target.value = '';
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        alert('Image is too large. Max size is 5MB.');
        event.target.value = '';
        return;
    }

    selectedChatImage = file;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('imagePreviewThumb').src = e.target.result;
        document.getElementById('imagePreviewBar').classList.add('show');
    };
    reader.readAsDataURL(file);
}

function removeSelectedImage() {
    selectedChatImage = null;
    document.getElementById('chatImageInput').value = '';
    document.getElementById('imagePreviewThumb').src = '';
    document.getElementById('imagePreviewBar').classList.remove('show');
}

// ── IMAGE SELECT (new conversation modal) ──
function handleNewMsgImageSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        alert('Please choose an image file.');
        event.target.value = '';
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        alert('Image is too large. Max size is 5MB.');
        event.target.value = '';
        return;
    }

    selectedNewMsgImage = file;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('newMsgImagePreviewThumb').src = e.target.result;
        document.getElementById('newMsgImagePreviewBar').classList.add('show');
    };
    reader.readAsDataURL(file);
}

function removeNewMsgImage() {
    selectedNewMsgImage = null;
    document.getElementById('new_message_image').value = '';
    document.getElementById('newMsgImagePreviewThumb').src = '';
    document.getElementById('newMsgImagePreviewBar').classList.remove('show');
}

// ── LIGHTBOX ──
function openLightbox(src) {
    document.getElementById('imgLightboxImg').src = src;
    document.getElementById('imgLightbox').classList.add('show');
}
function closeLightbox(event) {
    if (event) event.stopPropagation();
    document.getElementById('imgLightbox').classList.remove('show');
    document.getElementById('imgLightboxImg').src = '';
}

function sendMessage(e) {
    e.preventDefault();
    if (!currentConversation) return;
    
    const message = $('#messageText').val().trim();
    if (!message && !selectedChatImage) return;
    
    const sendBtn = document.querySelector('#sendMessageForm .btn-teal');
    const originalText = sendBtn.innerHTML;
    sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>';
    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('conversation_id', currentConversation);
    fd.append('message', message);
    if (selectedChatImage) fd.append('image', selectedChatImage);
    
    $.ajax({
        url: 'api/crm_messages.php?action=send',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(r) {
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            
            if (r.success) {
                $('#messageText').val('');
                removeSelectedImage();
                loadMessages(currentConversation);
                loadConversations();
            } else {
                showToast(r.message || 'Failed to send message', 'error');
            }
        },
        error: function() {
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            showToast('Connection error', 'error');
        }
    });
}

function sendNewMessage() {
    const receiver_type = $('#receiver_type').val();
    const receiver_id = $('#receiver_id').val();
    const message = $('#new_message').val().trim();
    
    if (!receiver_id || (!message && !selectedNewMsgImage)) {
        alert('Please select a recipient and enter a message or attach an image.');
        return;
    }
    
    const sendBtn = document.querySelector('#newMessageModal .btn-teal');
    const originalText = sendBtn.innerHTML;
    sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('receiver_type', receiver_type);
    fd.append('receiver_id', receiver_id);
    fd.append('message', message);
    if (selectedNewMsgImage) fd.append('image', selectedNewMsgImage);
    
    $.ajax({
        url: 'api/crm_messages.php?action=new_conversation',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(r) {
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            
            if (r.success) {
                closeNewMessageModal();
                
                $('#newMessageForm')[0].reset();
                removeNewMsgImage();
                loadRecipients();
                openConversation(r.conversation_id, r.receiver_name, receiver_type);
                loadConversations();
                showToast('Message sent successfully', 'success');
            } else {
                showToast(r.message || 'Failed to send', 'error');
            }
        },
        error: function() {
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            showToast('Connection error. Please try again.', 'error');
        }
    });
}

function showToast(message, type = 'success') {
    if (!$('#toastContainer').length) {
        $('body').append('<div id="toastContainer" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;"></div>');
    }
    
    const toastId = 'toast_' + Date.now();
    const bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi ${icon} me-2"></i> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    $('#toastContainer').append(toastHtml);
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
    
    toastElement.addEventListener('hidden.bs.toast', function() {
        $(this).remove();
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>