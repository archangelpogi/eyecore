<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    die('Access denied. SuperAdmin only.');
}

$userId = (int)$_SESSION['user_id'];
?>

<style>
    :root { --teal: #008080; --teal-dark: #006666; --teal-soft: #f0fdfa; }
    .inbox-container { display: flex; height: calc(100vh - 120px); background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
    .inbox-sidebar { width: 350px; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; flex-shrink: 0; }
    .inbox-header { padding: 1.2rem; border-bottom: 1px solid #e2e8f0; }
    .inbox-list { flex: 1; overflow-y: auto; }
    .thread-item { padding: 1rem 1.2rem; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: 0.2s; border-left: 4px solid transparent; }
    .thread-item:hover { background: #f8fafc; }
    .thread-item.active { background: var(--teal-soft); border-left-color: var(--teal); }
    .thread-avatar { width: 45px; height: 45px; border-radius: 12px; background: linear-gradient(135deg, #64748b, #334155); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; flex-shrink: 0; }
    .inbox-main { flex: 1; display: flex; flex-direction: column; background: #f8fafc; }
    .chat-header { padding: 1rem 1.5rem; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .chat-messages { flex: 1; padding: 1.5rem; overflow-y: auto; }
    .chat-input-area { padding: 1rem 1.5rem; background: #fff; border-top: 1px solid #e2e8f0; }
    .msg-bubble { max-width: 75%; padding: 0.75rem 1rem; border-radius: 18px; word-wrap: break-word; font-size: 0.9rem; }
    .msg-out { background: var(--teal); color: white; border-bottom-right-radius: 4px; margin-left: auto; }
    .msg-in { background: #fff; border: 1px solid #e2e8f0; color: #1e293b; border-bottom-left-radius: 4px; margin-right: auto; }
    .msg-time { font-size: 0.65rem; opacity: 0.7; margin-top: 4px; }
    .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #94a3b8; }
    .status-badge { background-color: #d1fae5; color: #065f46; border: 1px solid #10b981; border-radius: 20px; padding: 0.3rem 0.8rem; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
</style>

<div class="container-fluid py-4">
    <div class="inbox-container">
        <div class="inbox-sidebar">
            <div class="inbox-header">
                <h5 class="mb-0 fw-bold" style="color: var(--teal);"><i class="bi bi-headset me-2"></i>Support Inbox</h5>
            </div>
            <div class="inbox-list" id="threadsList">
                <div class="text-center p-4 text-muted">
                    <div class="spinner-border spinner-border-sm text-teal"></div> Loading...
                </div>
            </div>
        </div>

        <div class="inbox-main">
            <div class="chat-header d-none" id="chatHeader">
                <div>
                    <h6 class="mb-0 fw-bold" id="chatName">User Name</h6>
                    <small class="text-muted" id="chatClinic">Clinic Name</small>
                </div>
                <div>
                    <span class="status-badge" id="chatStatus">
                        <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> Open
                    </span>
                </div>
            </div>

            <div class="chat-messages" id="messagesArea">
                <div class="empty-state">
                    <i class="bi bi-chat-dots fs-1 mb-3 opacity-50"></i>
                    <h5>Select a conversation</h5>
                    <p class="small">Pumili ng ticket sa kaliwa para makita ang messages.</p>
                </div>
            </div>

            <div class="chat-input-area d-none" id="inputArea">
                <form id="sendForm" onsubmit="sendReply(event)" class="d-flex gap-2 align-items-center">
                    <input type="file" id="replyImage" accept="image/*" style="display: none;" onchange="handleReplyImage(event)">
                    <button type="button" class="btn btn-light border rounded-circle" onclick="document.getElementById('replyImage').click()" style="width: 40px; height: 40px; padding: 0;">
                        <i class="bi bi-image text-muted"></i>
                    </button>
                    <textarea id="replyText" class="form-control" rows="1" placeholder="Type your reply..." style="resize: none; border-radius: 12px; height: 40px;"></textarea>
                    <button type="submit" class="btn text-white" style="background: var(--teal); border-radius: 12px; height: 40px;">
                        <i class="bi bi-send"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ✅ IPAPASOK NATIN YUNG ADMIN ID DITO
const API = '../api/support_chat_admin.php';
const ADMIN_ID = <?= $userId ?>;
let currentThread = null;
let selectedImage = null;

document.addEventListener('DOMContentLoaded', function() {
    loadThreads();
    setInterval(function() {
        loadThreads();
        if (currentThread) loadMessages(currentThread);
    }, 5000);
});

// Helper para idagdag sa URL
function getApiUrl(action, extra = '') {
    return API + '?action=' + action + '&admin_id=' + ADMIN_ID + extra;
}

function loadThreads() {
    fetch(getApiUrl('get_threads'))
        .then(res => res.json())
        .then(data => {
            const list = document.getElementById('threadsList');
            if (!Array.isArray(data) || data.length === 0) {
                list.innerHTML = '<div class="text-center p-4 text-muted small">Walang tickets sa ngayon.</div>';
                return;
            }

            let html = '';
            data.forEach(t => {
                const activeClass = currentThread == t.id ? 'active' : '';
                const unreadBadge = t.unread > 0 ? `<span class="badge bg-danger rounded-pill ms-2">${t.unread}</span>` : '';
                
                const safeName = (t.creator_name || 'User').replace(/'/g, "\\'");
                const safeClinic = (t.clinic_name || 'No Clinic').replace(/'/g, "\\'");
                
                const names = (t.creator_name || 'User').split(' ');
                const initials = names.map(n => n.charAt(0)).slice(0, 2).join('').toUpperCase();

                html += `
                    <div class="thread-item ${activeClass}" onclick="openThread(${t.id}, '${safeName}', '${safeClinic}')">
                        <div class="d-flex align-items-center gap-3">
                            <div class="thread-avatar">${initials}</div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="fw-bold text-truncate" style="font-size: 0.85rem;">${t.creator_name || 'User'}</div>
                                    <small class="text-muted" style="font-size: 0.65rem;">${t.last_time}</small>
                                </div>
                                <div class="text-muted text-truncate" style="font-size: 0.75rem; font-weight: 500;">
                                    <i class="bi bi-shop"></i> ${t.clinic_name || 'No Clinic'}
                                </div>
                                <div class="text-muted text-truncate mt-1" style="font-size: 0.7rem;">
                                    ${t.last_message}
                                </div>
                                <div class="mt-1">${unreadBadge}</div>
                            </div>
                        </div>
                    </div>
                `;
            });
            list.innerHTML = html;
        })
        .catch(err => console.error(err));
}

function openThread(id, name, clinic) {
    currentThread = id;
    document.getElementById('chatHeader').classList.remove('d-none');
    document.getElementById('inputArea').classList.remove('d-none');
    document.getElementById('chatName').textContent = name;
    document.getElementById('chatClinic').textContent = clinic;
    
    document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
    loadMessages(id);
}

function loadMessages(id) {
    fetch(getApiUrl('get_messages', '&thread_id=' + id))
        .then(res => res.json())
        .then(data => {
            const area = document.getElementById('messagesArea');
            if (!Array.isArray(data) || data.length === 0) {
                area.innerHTML = '<div class="text-center text-muted mt-5">Walang messages.</div>';
                return;
            }

            let html = '';
            let lastDate = '';

            data.forEach(m => {
                // ✅ Kung SuperAdmin, ikaw ito (KANAN).
                const isMine = (m.sender_role === 'SuperAdmin');
                
                const bubbleClass = isMine ? 'msg-out' : 'msg-in';
                const align = isMine ? 'justify-content-end' : 'justify-content-start';
                
                let imgHtml = '';
                if (m.image) {
                    imgHtml = `<img src="${m.image}" class="img-fluid rounded mt-2" style="max-height: 200px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                }

                let msgDate = m.date || m.time || '';
                if (msgDate && msgDate !== lastDate) {
                    html += `
                        <div class="text-center my-3">
                            <span class="badge bg-light text-secondary border px-3 py-1 rounded-pill" style="font-size: 0.65rem;">
                                ${msgDate}
                            </span>
                        </div>
                    `;
                    lastDate = msgDate;
                }

                html += `
                    <div class="d-flex ${align} mb-3">
                        <div class="msg-bubble ${bubbleClass}">
                            ${!isMine ? `<div class="fw-bold mb-1" style="font-size:0.7rem; color:var(--teal);">${m.sender_name}</div>` : ''}
                            <div>${m.message}</div>
                            ${imgHtml}
                            <div class="msg-time text-end">${m.time}</div>
                        </div>
                    </div>
                `;
            });

            area.innerHTML = html;
            area.scrollTop = area.scrollHeight;
        })
        .catch(err => console.error(err));
}

function sendReply(e) {
    e.preventDefault();
    if (!currentThread) return;
    const text = document.getElementById('replyText').value.trim();
    if (!text && !selectedImage) return;

    const fd = new FormData();
    fd.append('admin_id', ADMIN_ID);
    fd.append('thread_id', currentThread);
    fd.append('message', text);
    if (selectedImage) fd.append('image', selectedImage);

    document.getElementById('replyText').value = '';
    document.getElementById('replyText').style.height = '40px';
    
    fetch(getApiUrl('send_message'), { method: 'POST', body: fd })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); } 
            catch (err) { 
                console.error("Server returned:", text);
                throw new Error("Invalid JSON from server.");
            }
        })
        .then(data => {
            if (data.success) {
                removeImage();
                loadMessages(currentThread);
                loadThreads();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Network Error: ' + err.message));
}

function handleReplyImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Max 5MB only.'); return; }
    selectedImage = file;
}

function removeImage() {
    selectedImage = null;
    document.getElementById('replyImage').value = '';
}
</script>