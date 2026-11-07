<?php
if (!isset($_SESSION['user_id'])) return;
if (($_SESSION['role'] ?? '') === 'SuperAdmin') return; 

$widget_user_id = (int)$_SESSION['user_id'];
$widget_role = $_SESSION['role'] ?? 'User';
?>
<!-- Support Chat Floating Widget -->
<div id="supportWidget" style="position: fixed; bottom: 25px; right: 25px; z-index: 9999; font-family: 'Segoe UI', system-ui, sans-serif;">
    <!-- Toggle Button (Gamit ang Bootstrap Icons) -->
    <button id="supportToggleBtn" class="btn rounded-circle shadow-lg d-flex align-items-center justify-content-center" 
            style="width: 60px; height: 60px; background-color: #0d9488; border: none; transition: transform 0.2s; position: relative;">
        <!-- ✅ Bootstrap Icon para siguradong lumabas -->
        <i class="bi bi-headset fs-3 text-white"></i>
        <span id="supportUnreadBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size: 0.7rem; border: 2px solid white;">
            0
        </span>
    </button>

    <!-- Chat Window -->
    <div id="supportChatWindow" class="card shadow-lg border-0 d-none" 
         style="position: absolute; bottom: 75px; right: 0; width: 350px; height: 480px; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,0.2) !important;">
        
        <!-- Header -->
        <div class="card-header text-white d-flex justify-content-between align-items-center py-3" style="background-color: #0d9488; border-bottom: none;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-headset fs-5"></i>
                <span class="fw-bold">Chat with Eyecore</span>
            </div>
            <button type="button" class="btn-close btn-close-white" onclick="toggleSupportWidget()" style="font-size: 0.8rem;"></button>
        </div>

        <!-- Messages Area -->
        <div id="supportMessagesArea" class="flex-grow-1 p-3" style="overflow-y: auto; background-color: #f8f9fa;">
            <div class="text-center text-muted small mt-3">
                <i class="bi bi-chat-text fs-2 d-block mb-2 text-secondary opacity-50"></i>
                Hello! Are you experiencing any issues with the system? Just type your message here and the developer will respond shortly.
            </div>
        </div>

        <!-- Image Preview -->
        <div id="supportImgPreview" class="bg-white px-2 py-1 border-top d-none" style="position: relative;">
            <div class="d-flex align-items-center gap-2">
                <img id="supportImgThumb" src="" style="width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #ccc;">
                <span class="small text-muted flex-grow-1">Image ready to send</span>
                <button type="button" class="btn btn-sm btn-danger rounded-circle" onclick="removeSupImage()" style="width:24px; height:24px; padding:0;"><i class="bi bi-x"></i></button>
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-2 bg-white border-top">
            <form id="supportChatForm" class="d-flex gap-2 align-items-center" onsubmit="sendSupportMessage(event)">
                <input type="file" id="supImageInput" accept="image/*" style="display: none;" onchange="handleSupImage(event)">
                <button type="button" class="btn btn-light border rounded-circle" onclick="document.getElementById('supImageInput').click()" style="width: 36px; height: 36px; padding: 0;">
                    <i class="bi bi-image text-muted"></i>
                </button>
                <textarea id="supportMessageInput" class="form-control form-control-sm" rows="1" placeholder="Type your message..." style="resize: none; border-radius: 12px; height: 36px;"></textarea>
                <button type="submit" class="btn btn-sm text-white rounded-circle" style="background-color: #0d9488; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-send"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const SUPPORT_API = '/api/support_chat_user.php'; 
const SUPPORT_USER_ID = <?= $widget_user_id ?>;
const SUPPORT_ROLE = '<?= $widget_role ?>';

document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('supportToggleBtn');
    const chatWindow = document.getElementById('supportChatWindow');
    const badge = document.getElementById('supportUnreadBadge');
    let activeThreadId = null;
    let chatInterval = null;
    let selectedImage = null;

    function getApiUrl(action, extra = '') {
        return SUPPORT_API + '?action=' + action + '&user_id=' + SUPPORT_USER_ID + '&role=' + SUPPORT_ROLE + extra;
    }

    const textarea = document.getElementById('supportMessageInput');
    textarea.addEventListener('input', function() {
        this.style.height = '36px';
        this.style.height = (this.scrollHeight) + 'px';
        if(this.scrollHeight > 100) this.style.height = '100px';
    });

    window.toggleSupportWidget = function() {
        if (chatWindow.classList.contains('d-none')) {
            chatWindow.classList.remove('d-none');
            initSupportChat();
            chatInterval = setInterval(checkUnread, 5000);
        } else {
            chatWindow.classList.add('d-none');
            clearInterval(chatInterval);
        }
    };
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', window.toggleSupportWidget);
    }

    function checkUnread() {
        fetch(getApiUrl('get_unread_count'))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.unread > 0) {
                    badge.textContent = data.unread;
                    badge.classList.remove('d-none');
                    if (activeThreadId) loadSupportMessages(); 
                } else {
                    badge.classList.add('d-none');
                }
            }).catch(e => console.log(e));
    }
    setInterval(checkUnread, 15000); 
    checkUnread();

    function initSupportChat() {
        fetch(getApiUrl('get_threads'))
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    activeThreadId = data[0].id;
                    loadSupportMessages();
                } else {
                    document.getElementById('supportMessagesArea').innerHTML = `
                        <div class="text-center text-muted small mt-3">
                            <i class="bi bi-chat-text fs-2 d-block mb-2 text-secondary opacity-50"></i>
                            Hello! Are you experiencing any issues with the system? Just type your message here and the developer will respond shortly.
                        </div>`;
                }
            });
    }

    window.loadSupportMessages = function() {
        if (!activeThreadId) return;
        fetch(getApiUrl('get_messages', '&thread_id=' + activeThreadId))
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data)) return;
                const container = document.getElementById('supportMessagesArea');
                
                if(data.length === 0) return;

                let html = '';
                let lastDate = '';

                data.forEach(m => {
                    const isMine = (m.sender_role !== 'SuperAdmin');

                    const bubbleClass = isMine ? 'text-white ml-auto' : 'bg-white border text-dark';
                    const bgStyle = isMine ? 'background-color: #0d9488;' : '';
                    const align = isMine ? 'justify-content-end' : 'justify-content-start';
                    const sender = !isMine ? `<div class="fw-bold mb-1" style="font-size:0.7rem; color:#0d9488;">${m.sender_name}</div>` : '';
                    
                    let imgHtml = '';
                    if (m.image) {
                        imgHtml = `<img src="${m.image}" class="img-fluid rounded mt-1 mb-1" style="max-height: 150px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                    }

                    let msgDate = m.date || m.time || '';
                    if (msgDate && msgDate !== lastDate) {
                        html += `
                            <div class="text-center my-3">
                                <span class="badge bg-light text-secondary border px-3 py-1 rounded-pill" style="font-size: 0.65rem; font-weight: 600;">
                                    ${msgDate}
                                </span>
                            </div>
                        `;
                        lastDate = msgDate;
                    }

                    html += `
                        <div class="d-flex ${align} mb-2">
                            <div class="p-2 rounded ${bubbleClass}" style="max-width: 85%; font-size: 0.85rem; border-radius: 12px !important; word-break: break-word; ${bgStyle}">
                                ${sender}
                                <div style="margin-bottom: 2px;">${m.message}</div>
                                ${imgHtml}
                                <div class="text-right mt-1" style="font-size: 0.6rem; opacity: 0.8;">
                                    ${m.time}
                                </div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
                container.scrollTop = container.scrollHeight;
            });
    }

    window.sendSupportMessage = function(e) {
        e.preventDefault();
        const msgInput = document.getElementById('supportMessageInput');
        const message = msgInput.value.trim();
        if (!message && !selectedImage) return;

        msgInput.value = '';
        msgInput.style.height = '36px';
        
        const fd = new FormData();
        fd.append('user_id', SUPPORT_USER_ID);
        fd.append('role', SUPPORT_ROLE);
        if (activeThreadId) fd.append('thread_id', activeThreadId);
        fd.append('message', message);
        if (selectedImage) fd.append('image', selectedImage);

        const container = document.getElementById('supportMessagesArea');
        if(container.querySelector('.text-center')) container.innerHTML = '';

        const endpoint = activeThreadId ? 'send_message' : 'create_thread';
        if(!activeThreadId) fd.append('subject', 'Support Request');

        fetch(getApiUrl(endpoint), { method: 'POST', body: fd })
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
                    if (!activeThreadId && data.thread_id) {
                        activeThreadId = data.thread_id;
                    }
                    removeSupImage();
                    loadSupportMessages();
                } else {
                    alert('System Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Network Error: ' + err.message));
    };

    window.handleSupImage = function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { alert('Max 5MB only.'); return; }
        selectedImage = file;
        
        const reader = new FileReader();
        reader.onload = function(evt) {
            document.getElementById('supportImgThumb').src = evt.target.result;
            document.getElementById('supportImgPreview').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    };

    window.removeSupImage = function() {
        selectedImage = null;
        document.getElementById('supImageInput').value = '';
        document.getElementById('supportImgPreview').classList.add('d-none');
    };
});
</script>