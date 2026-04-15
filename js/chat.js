/**
 * 群聊：轮询消息与在线成员（按房间）
 *
 * @copyright 千寻百念工作室
 */

const roomId = parseInt(document.body.dataset.roomId || '0', 10);
let lastMessageId = 0;
let lastUserUpdate = 0;
let uploading = false;
/** 避免并发 loadMessages 用同一 last_id 重复拉取并重复 append */
let loadMessagesInFlight = false;
let loadMessagesQueued = false;

/** 与上一条消息间隔超过此秒数则显示时间条（类似微信） */
const CHAT_TIME_GAP_SEC = 300;

let sessionEnterInserted = false;
/** 已有「进入聊天」时间条时，下一条消息不再单独插一条时间（避免顶部两条时间） */
let suppressNextMessageTimeSep = false;
/** @type {number|null} 上一条已展示气泡对应的消息 created_at（Unix 秒） */
let prevMessageTs = null;

/** 常用表情（点击插入输入框，作「图片表情包」快捷输入） */
const EMOJI_LIST = [
    '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊',
    '😇', '🙂', '😉', '😌', '😍', '🥰', '😘', '😗',
    '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭',
    '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏',
    '😒', '🙄', '😬', '🤥', '😌', '😔', '😪', '🤤',
    '😴', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '🥵',
    '🥶', '🥴', '😵', '🤯', '🤠', '🥳', '😎', '🤓',
    '🧐', '😕', '😟', '🙁', '☹️', '😮', '😯', '😲',
    '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢',
    '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫',
    '🥱', '🙃', '😤', '😡', '😠', '🤬', '😈', '👿',
    '💀', '☠️', '💩', '🤡', '👹', '👺', '👻', '👽',
];

const messagesContainer = document.getElementById('messages');
const messageForm = document.getElementById('message-form');
const messageInput = document.getElementById('message');
const usersList = document.getElementById('users-list');
const refreshUsersBtn = document.getElementById('refresh-users');
const onlineModal = document.getElementById('online-modal');
const openOnlineModalBtn = document.getElementById('open-online-modal');
const closeOnlineModalBtn = document.getElementById('close-online-modal');
const onlineModalBackdrop = document.getElementById('online-modal-backdrop');
const onlineCountEl = document.getElementById('online-count');
const shareRoomBtn = document.getElementById('share-room-btn');
const shareRoomPanel = document.getElementById('share-room-panel');
const shareRoomLink = document.getElementById('share-room-link');
const copyRoomLinkBtn = document.getElementById('copy-room-link');
const shareRoomQr = document.getElementById('share-room-qr');
const roomPasswordBtn = document.getElementById('room-password-btn');
const roomPasswordModal = document.getElementById('room-password-modal');
const roomPasswordBackdrop = document.getElementById('room-password-backdrop');
const closeRoomPasswordModalBtn = document.getElementById('close-room-password-modal');
const roomPasswordForm = document.getElementById('room-password-form');
const roomPasswordInput = document.getElementById('room-password-input');
const roomPasswordConfirm = document.getElementById('room-password-confirm');
const roomPasswordClear = document.getElementById('room-password-clear');
const roomPasswordCancel = document.getElementById('room-password-cancel');
const roomPasswordSave = document.getElementById('room-password-save');
const roomBroadcastWrap = document.getElementById('room-broadcast-wrap');
const roomBroadcastText = document.getElementById('room-broadcast-text');
const roomBroadcastBtn = document.getElementById('room-broadcast-btn');
const roomBroadcastModal = document.getElementById('room-broadcast-modal');
const roomBroadcastBackdrop = document.getElementById('room-broadcast-backdrop');
const closeRoomBroadcastModalBtn = document.getElementById('close-room-broadcast-modal');
const roomBroadcastForm = document.getElementById('room-broadcast-form');
const roomBroadcastInput = document.getElementById('room-broadcast-input');
const roomBroadcastCancel = document.getElementById('room-broadcast-cancel');
const roomBroadcastSave = document.getElementById('room-broadcast-save');
const clearRoomBtn = document.getElementById('clear-room-btn');
const imagePreviewModal = document.getElementById('image-preview-modal');
const imagePreviewBackdrop = document.getElementById('image-preview-backdrop');
const closeImagePreviewBtn = document.getElementById('close-image-preview');
const imagePreviewImg = document.getElementById('image-preview-img');
const permModal = document.getElementById('perm-modal');
const permModalBackdrop = document.getElementById('perm-modal-backdrop');
const closePermModalBtn = document.getElementById('close-perm-modal');
const permForm = document.getElementById('perm-form');
const permUserName = document.getElementById('perm-user-name');
const permIsAdmin = document.getElementById('perm-is-admin');
const permIsFrozen = document.getElementById('perm-is-frozen');
const permIsMuted = document.getElementById('perm-is-muted');
const permCanUpload = document.getElementById('perm-can-upload');
const permCancel = document.getElementById('perm-cancel');
const permSave = document.getElementById('perm-save');
const openMyMenuBtn = document.getElementById('open-my-menu');
const myMenu = document.getElementById('my-menu');
const myEditNicknameBtn = document.getElementById('my-edit-nickname');
const profileModal = document.getElementById('profile-modal');
const profileModalBackdrop = document.getElementById('profile-modal-backdrop');
const closeProfileModalBtn = document.getElementById('close-profile-modal');
const profileForm = document.getElementById('profile-form');
const profileNickname = document.getElementById('profile-nickname');
const profileCancel = document.getElementById('profile-cancel');
const profileSave = document.getElementById('profile-save');
const emojiPanel = document.getElementById('emoji-panel');
const emojiToggle = document.getElementById('emoji-toggle');
const inputImage = document.getElementById('input-image');
const inputFile = document.getElementById('input-file');
const replyBox = document.getElementById('reply-box');
const replyText = document.getElementById('reply-text');
const replyCancel = document.getElementById('reply-cancel');
let touchRecallTimer = null;
let touchRecallMoved = false;
let replyToMessageId = 0;
let editingUserId = 0;
let currentBroadcastText = '';
let lastImageTapAt = 0;
let lastImageTapMessageId = 0;

function closeAllRecallActions(exceptEl = null) {
    if (!messagesContainer) return;
    messagesContainer.querySelectorAll('.message.show-actions').forEach((el) => {
        if (exceptEl && el === exceptEl) return;
        el.classList.remove('show-actions');
    });
}

function toggleRecallActionsByEvent(e) {
    const bubble = e.target && e.target.closest ? e.target.closest('.message-bubble') : null;
    if (!bubble) {
        closeAllRecallActions();
        return;
    }
    const msgEl = bubble.closest('.message');
    if (!msgEl) return;
    const canReply = msgEl.getAttribute('data-can-reply') === '1';
    const canRecall = msgEl.getAttribute('data-can-recall') === '1';
    const mid = parseInt(msgEl.getAttribute('data-message-id') || '0', 10);
    if ((!canRecall && !canReply) || !mid) {
        closeAllRecallActions();
        return;
    }
    // 不在链接点击时弹菜单
    const link = e.target && e.target.closest ? e.target.closest('a') : null;
    if (link) return;

    e.preventDefault();
    e.stopPropagation();
    const open = msgEl.classList.contains('show-actions');
    closeAllRecallActions(msgEl);
    msgEl.classList.toggle('show-actions', !open);
}

function isCoarsePointerDevice() {
    try {
        return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    } catch (_) {
        return false;
    }
}

function clearReplyTarget() {
    replyToMessageId = 0;
    if (replyBox) replyBox.hidden = true;
    if (replyText) replyText.textContent = '';
}

function setReplyTarget(messageId, username, preview) {
    replyToMessageId = messageId;
    if (!replyBox || !replyText) return;
    const u = (username || '').trim() || '对方';
    const p = (preview || '').trim() || '消息';
    replyText.textContent = `${u}: ${p}`;
    replyBox.hidden = false;
    if (messageInput) messageInput.focus();
}

function openPermModal() {
    if (!permModal) return;
    permModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closePermModal() {
    if (!permModal) return;
    permModal.hidden = true;
    editingUserId = 0;
    document.body.style.overflow = '';
}

function openMyMenu() {
    if (!myMenu) return;
    myMenu.hidden = false;
}

function closeMyMenu() {
    if (!myMenu) return;
    myMenu.hidden = true;
}

function openProfileModal() {
    if (!profileModal) return;
    if (profileNickname) {
        profileNickname.value = (document.body.dataset.myNickname || '').trim();
    }
    profileModal.hidden = false;
    closeMyMenu();
    document.body.style.overflow = 'hidden';
    if (profileNickname) profileNickname.focus();
}

function closeProfileModal() {
    if (!profileModal) return;
    profileModal.hidden = true;
    document.body.style.overflow = '';
}

function openImagePreview(src) {
    if (!imagePreviewModal || !imagePreviewImg || !src) return;
    imagePreviewImg.src = src;
    imagePreviewModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeImagePreview() {
    if (!imagePreviewModal || !imagePreviewImg) return;
    imagePreviewModal.hidden = true;
    imagePreviewImg.src = '';
    document.body.style.overflow = '';
}

function currentRoomShareUrl() {
    return `${window.location.origin}${window.location.pathname}?id=${roomId}`;
}

function openRoomPasswordModal() {
    if (!roomPasswordModal) return;
    roomPasswordModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeRoomPasswordModal() {
    if (!roomPasswordModal) return;
    roomPasswordModal.hidden = true;
    if (roomPasswordForm) roomPasswordForm.reset();
    document.body.style.overflow = '';
}

function renderRoomBroadcast(text) {
    const val = String(text || '').trim();
    currentBroadcastText = val;
    if (!roomBroadcastWrap || !roomBroadcastText) return;
    if (val === '') {
        roomBroadcastWrap.hidden = true;
        roomBroadcastText.textContent = '';
        return;
    }
    roomBroadcastText.textContent = val;
    // 触发重绘，确保更新后滚动动画从右侧重新开始
    roomBroadcastText.style.animation = 'none';
    void roomBroadcastText.offsetWidth;
    roomBroadcastText.style.animation = '';
    roomBroadcastWrap.hidden = false;
}

async function loadRoomBroadcast() {
    try {
        const resp = await fetch(`api/room_broadcast.php?room_id=${roomId}`);
        const data = await resp.json();
        if (!data || !data.success) {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) return;
            return;
        }
        const text = String(data.broadcast_text || '').trim();
        if (text !== currentBroadcastText) {
            renderRoomBroadcast(text);
        }
    } catch (err) {
        console.error('加载群广播失败:', err);
    }
}

function openRoomBroadcastModal() {
    if (!roomBroadcastModal) return;
    if (roomBroadcastInput) {
        roomBroadcastInput.value = currentBroadcastText;
    }
    roomBroadcastModal.hidden = false;
    document.body.style.overflow = 'hidden';
    if (roomBroadcastInput) roomBroadcastInput.focus();
}

function closeRoomBroadcastModal() {
    if (!roomBroadcastModal) return;
    roomBroadcastModal.hidden = true;
    document.body.style.overflow = '';
}

async function openUserPermissions(uid, label) {
    if (!uid || !permModal) return;
    try {
        editingUserId = uid;
        if (permUserName) {
            permUserName.textContent = `${label || '用户'}（ID: ${uid}）`;
        }
        openPermModal();
        const resp = await fetch(`api/user_permissions.php?room_id=${roomId}&user_id=${uid}`);
        const data = await resp.json();
        if (!data || !data.success || !data.user) {
            window.alert((data && data.error) ? data.error : '加载权限失败');
            closePermModal();
            return;
        }
        if (permIsAdmin) permIsAdmin.checked = Number(data.user.is_admin) === 1;
        if (permIsFrozen) permIsFrozen.checked = Number(data.user.is_frozen) === 1;
        if (permIsMuted) permIsMuted.checked = Number(data.user.is_muted) === 1;
        if (permCanUpload) permCanUpload.checked = Number(data.user.can_upload_file) === 1;
        if (Number(data.user.is_me) === 1 && permIsFrozen) {
            permIsFrozen.disabled = true;
        } else if (permIsFrozen) {
            permIsFrozen.disabled = false;
        }
    } catch (err) {
        console.error('加载权限失败:', err);
        window.alert('加载权限失败');
        closePermModal();
    }
}

async function recallMessage(messageId) {
    const ok = window.confirm('确认撤回这条消息？');
    if (!ok) return;
    try {
        const fd = new FormData();
        fd.append('room_id', String(roomId));
        fd.append('message_id', String(messageId));
        const resp = await fetch('api/recall_message.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data && data.success) {
            closeAllRecallActions();
            lastMessageId = 0;
            messagesContainer.innerHTML = '';
            prevMessageTs = null;
            sessionEnterInserted = false;
            suppressNextMessageTimeSep = false;
            insertSessionEnterLine();
            loadMessages();
        } else {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) return;
            window.alert((data && data.error) ? data.error : '撤回失败');
        }
    } catch (err) {
        console.error('撤回失败:', err);
        window.alert('撤回失败，请重试');
    }
}

/**
 * @param {number} tsSec Unix 秒
 * @returns {string}
 */
function formatChatTime(tsSec) {
    const d = new Date(tsSec * 1000);
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const hm = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const dayStart = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diffDays = Math.round((dayStart(now) - dayStart(d)) / 86400000);
    if (diffDays === 0) {
        return hm;
    }
    if (diffDays === 1) {
        return `昨天 ${hm}`;
    }
    if (d.getFullYear() === now.getFullYear()) {
        return `${d.getMonth() + 1}月${d.getDate()}日 ${hm}`;
    }
    return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日 ${hm}`;
}

/**
 * @param {string} label
 * @returns {HTMLDivElement}
 */
function createTimeSep(label) {
    const wrap = document.createElement('div');
    wrap.className = 'chat-time-sep';
    wrap.setAttribute('role', 'status');
    const inner = document.createElement('span');
    inner.className = 'chat-time-sep-text';
    inner.textContent = label;
    wrap.appendChild(inner);
    return wrap;
}

function insertSessionEnterLine() {
    if (sessionEnterInserted || !messagesContainer) {
        return;
    }
    sessionEnterInserted = true;
    suppressNextMessageTimeSep = true;
    const nowSec = Math.floor(Date.now() / 1000);
    messagesContainer.appendChild(createTimeSep(`进入聊天 ${formatChatTime(nowSec)}`));
}

function maybeInsertMessageTimeSep(tsSec) {
    if (!messagesContainer) {
        return;
    }
    if (suppressNextMessageTimeSep) {
        suppressNextMessageTimeSep = false;
        return;
    }
    if (prevMessageTs === null) {
        messagesContainer.appendChild(createTimeSep(formatChatTime(tsSec)));
    } else if (tsSec - prevMessageTs >= CHAT_TIME_GAP_SEC) {
        messagesContainer.appendChild(createTimeSep(formatChatTime(tsSec)));
    }
}

function redirectIfRoomLocked(data) {
    if (data && data.need_room_password) {
        const isGuest = document.body.dataset.isGuest === '1';
        const ret = isGuest ? 'guest' : 'chat';
        window.location.href = `room_gate.php?id=${roomId}&return=${ret}`;
        return true;
    }
    return false;
}

function redirectIfAccountFrozen(data) {
    if (data && data.account_frozen) {
        window.location.href = 'login.php?frozen=1';
        return true;
    }
    return false;
}

document.addEventListener('DOMContentLoaded', () => {
    if (!roomId) {
        console.error('缺少房间 ID');
        return;
    }

    const canUpload = document.body.dataset.canUpload !== '0';
    const isMuted = document.body.dataset.isMuted === '1';

    if (!canUpload) {
        if (inputImage) {
            inputImage.disabled = true;
            const lb = inputImage.closest('label');
            if (lb) {
                lb.classList.add('input-tool-btn--off');
                lb.setAttribute('title', '无发送文件权限');
            }
        }
        if (inputFile) {
            inputFile.disabled = true;
            const lb = inputFile.closest('label');
            if (lb) {
                lb.classList.add('input-tool-btn--off');
                lb.setAttribute('title', '无发送文件权限');
            }
        }
    }

    if (isMuted) {
        if (messageInput) {
            messageInput.disabled = true;
            messageInput.placeholder = '您已被禁言';
        }
        const sendBtn = messageForm && messageForm.querySelector('button[type="submit"]');
        if (sendBtn) {
            sendBtn.disabled = true;
        }
        if (emojiToggle) {
            emojiToggle.disabled = true;
            emojiToggle.classList.add('input-tool-btn--off');
        }
        if (inputImage) {
            inputImage.disabled = true;
            const lb = inputImage.closest('label');
            if (lb) lb.classList.add('input-tool-btn--off');
        }
        if (inputFile) {
            inputFile.disabled = true;
            const lb = inputFile.closest('label');
            if (lb) lb.classList.add('input-tool-btn--off');
        }
    }

    insertSessionEnterLine();
    loadMessages();
    loadOnlineUsers();
    loadRoomBroadcast();

    setInterval(loadMessages, 2000);
    setInterval(loadOnlineUsers, 5000);
    setInterval(loadRoomBroadcast, 5000);

    messageForm.addEventListener('submit', sendMessage);
    if (replyCancel) {
        replyCancel.addEventListener('click', clearReplyTarget);
    }

    refreshUsersBtn.addEventListener('click', () => {
        lastUserUpdate = 0;
        loadOnlineUsers(true);
    });
    if (shareRoomBtn && shareRoomPanel) {
        shareRoomBtn.addEventListener('click', () => {
            const opening = shareRoomPanel.hidden;
            shareRoomPanel.hidden = !opening;
            if (!opening) return;
            const url = currentRoomShareUrl();
            if (shareRoomLink) {
                shareRoomLink.value = url;
            }
            if (shareRoomQr) {
                shareRoomQr.src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(url)}`;
            }
        });
    }
    if (roomPasswordBtn) {
        roomPasswordBtn.addEventListener('click', openRoomPasswordModal);
    }
    if (roomBroadcastBtn) {
        roomBroadcastBtn.addEventListener('click', openRoomBroadcastModal);
    }
    if (roomPasswordBackdrop) {
        roomPasswordBackdrop.addEventListener('click', closeRoomPasswordModal);
    }
    if (roomBroadcastBackdrop) {
        roomBroadcastBackdrop.addEventListener('click', closeRoomBroadcastModal);
    }
    if (closeRoomPasswordModalBtn) {
        closeRoomPasswordModalBtn.addEventListener('click', closeRoomPasswordModal);
    }
    if (closeRoomBroadcastModalBtn) {
        closeRoomBroadcastModalBtn.addEventListener('click', closeRoomBroadcastModal);
    }
    if (roomPasswordCancel) {
        roomPasswordCancel.addEventListener('click', closeRoomPasswordModal);
    }
    if (roomBroadcastCancel) {
        roomBroadcastCancel.addEventListener('click', closeRoomBroadcastModal);
    }
    if (imagePreviewBackdrop) {
        imagePreviewBackdrop.addEventListener('click', closeImagePreview);
    }
    if (closeImagePreviewBtn) {
        closeImagePreviewBtn.addEventListener('click', closeImagePreview);
    }
    if (roomPasswordForm) {
        roomPasswordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const clear = !!(roomPasswordClear && roomPasswordClear.checked);
            const pass = roomPasswordInput ? roomPasswordInput.value : '';
            const pass2 = roomPasswordConfirm ? roomPasswordConfirm.value : '';
            if (!clear) {
                if (!pass || !pass2) {
                    window.alert('请输入并确认房间密码');
                    return;
                }
                if (pass !== pass2) {
                    window.alert('房间密码两次输入不一致');
                    return;
                }
                if (pass.length < 4) {
                    window.alert('房间密码至少4位');
                    return;
                }
            }
            if (roomPasswordSave) roomPasswordSave.disabled = true;
            try {
                const fd = new FormData();
                fd.append('room_id', String(roomId));
                if (clear) {
                    fd.append('clear_password', '1');
                } else {
                    fd.append('room_password', pass);
                    fd.append('room_password_confirm', pass2);
                }
                const resp = await fetch('api/update_room_password.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success) {
                    if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) return;
                    window.alert((data && data.error) ? data.error : '保存失败');
                    return;
                }
                closeRoomPasswordModal();
                window.alert(clear ? '已清空房间密码' : '房间密码已更新');
            } catch (err) {
                console.error('更新房间密码失败:', err);
                window.alert('更新房间密码失败');
            } finally {
                if (roomPasswordSave) roomPasswordSave.disabled = false;
            }
        });
    }
    if (roomBroadcastForm) {
        roomBroadcastForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = roomBroadcastInput ? roomBroadcastInput.value.trim() : '';
            if (roomBroadcastSave) roomBroadcastSave.disabled = true;
            try {
                const fd = new FormData();
                fd.append('room_id', String(roomId));
                fd.append('broadcast_text', text);
                const resp = await fetch('api/room_broadcast.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success) {
                    if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) return;
                    window.alert((data && data.error) ? data.error : '保存广播失败');
                    return;
                }
                renderRoomBroadcast(String(data.broadcast_text || ''));
                closeRoomBroadcastModal();
            } catch (err) {
                console.error('保存广播失败:', err);
                window.alert('保存广播失败');
            } finally {
                if (roomBroadcastSave) roomBroadcastSave.disabled = false;
            }
        });
    }
    if (clearRoomBtn) {
        clearRoomBtn.addEventListener('click', async () => {
            const ok = window.confirm('确认清理当前房间全部聊天记录？该操作不可恢复。');
            if (!ok) return;
            clearRoomBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('room_id', String(roomId));
                const resp = await fetch('api/clear_room_messages.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success) {
                    if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) return;
                    window.alert((data && data.error) ? data.error : '清理失败');
                    return;
                }
                closeOnlineModal();
                closeAllRecallActions();
                lastMessageId = 0;
                messagesContainer.innerHTML = '';
                prevMessageTs = null;
                sessionEnterInserted = false;
                suppressNextMessageTimeSep = false;
                insertSessionEnterLine();
                loadMessages();
                window.alert('已清理当前房间聊天记录');
            } catch (err) {
                console.error('清理记录失败:', err);
                window.alert('清理记录失败');
            } finally {
                clearRoomBtn.disabled = false;
            }
        });
    }
    if (copyRoomLinkBtn && shareRoomLink) {
        copyRoomLinkBtn.addEventListener('click', async () => {
            const text = shareRoomLink.value.trim();
            if (!text) return;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    shareRoomLink.select();
                    document.execCommand('copy');
                }
                copyRoomLinkBtn.textContent = '已复制';
                setTimeout(() => {
                    copyRoomLinkBtn.textContent = '复制';
                }, 1200);
            } catch (err) {
                window.alert('复制失败，请手动复制链接');
            }
        });
    }

    function openOnlineModal() {
        if (!onlineModal) return;
        onlineModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeOnlineModal() {
        if (!onlineModal) return;
        onlineModal.hidden = true;
        if (shareRoomPanel) {
            shareRoomPanel.hidden = true;
        }
        document.body.style.overflow = '';
    }

    if (openOnlineModalBtn) {
        openOnlineModalBtn.addEventListener('click', openOnlineModal);
    }
    if (closeOnlineModalBtn) {
        closeOnlineModalBtn.addEventListener('click', closeOnlineModal);
    }
    if (onlineModalBackdrop) {
        onlineModalBackdrop.addEventListener('click', closeOnlineModal);
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeMyMenu();
        }
        if (e.key === 'Escape' && onlineModal && !onlineModal.hidden) {
            closeOnlineModal();
        }
        if (e.key === 'Escape' && permModal && !permModal.hidden) {
            closePermModal();
        }
        if (e.key === 'Escape' && profileModal && !profileModal.hidden) {
            closeProfileModal();
        }
        if (e.key === 'Escape' && roomPasswordModal && !roomPasswordModal.hidden) {
            closeRoomPasswordModal();
        }
        if (e.key === 'Escape' && roomBroadcastModal && !roomBroadcastModal.hidden) {
            closeRoomBroadcastModal();
        }
        if (e.key === 'Escape' && imagePreviewModal && !imagePreviewModal.hidden) {
            closeImagePreview();
        }
    });
    if (openMyMenuBtn && myMenu) {
        openMyMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (myMenu.hidden) {
                openMyMenu();
            } else {
                closeMyMenu();
            }
        });
    }
    if (myEditNicknameBtn) {
        myEditNicknameBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            openProfileModal();
        });
    }
    if (profileModalBackdrop) {
        profileModalBackdrop.addEventListener('click', closeProfileModal);
    }
    if (closeProfileModalBtn) {
        closeProfileModalBtn.addEventListener('click', closeProfileModal);
    }
    if (profileCancel) {
        profileCancel.addEventListener('click', closeProfileModal);
    }
    if (profileForm) {
        profileForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const nick = profileNickname ? profileNickname.value.trim() : '';
            if (nick.length < 2 || nick.length > 20) {
                window.alert('昵称长度需为2-20个字符');
                return;
            }
            if (profileSave) profileSave.disabled = true;
            try {
                const fd = new FormData();
                fd.append('room_id', String(roomId));
                fd.append('nickname', nick);
                const resp = await fetch('api/update_profile.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success) {
                    window.alert((data && data.error) ? data.error : '保存失败');
                    return;
                }
                document.body.dataset.myNickname = nick;
                closeProfileModal();
                lastMessageId = 0;
                messagesContainer.innerHTML = '';
                prevMessageTs = null;
                sessionEnterInserted = false;
                suppressNextMessageTimeSep = false;
                insertSessionEnterLine();
                loadMessages();
                loadOnlineUsers(true);
            } catch (err) {
                console.error('修改昵称失败:', err);
                window.alert('修改昵称失败');
            } finally {
                if (profileSave) profileSave.disabled = false;
            }
        });
    }
    if (closePermModalBtn) {
        closePermModalBtn.addEventListener('click', closePermModal);
    }
    if (permModalBackdrop) {
        permModalBackdrop.addEventListener('click', closePermModal);
    }
    if (permCancel) {
        permCancel.addEventListener('click', closePermModal);
    }
    if (permForm) {
        permForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!editingUserId) return;
            if (permSave) permSave.disabled = true;
            try {
                const fd = new FormData();
                fd.append('room_id', String(roomId));
                fd.append('user_id', String(editingUserId));
                if (permIsAdmin && permIsAdmin.checked) fd.append('is_admin', '1');
                if (permIsFrozen && permIsFrozen.checked) fd.append('is_frozen', '1');
                if (permIsMuted && permIsMuted.checked) fd.append('is_muted', '1');
                if (permCanUpload && permCanUpload.checked) fd.append('can_upload_file', '1');
                const resp = await fetch('api/user_permissions.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success) {
                    window.alert((data && data.error) ? data.error : '保存失败');
                    return;
                }
                closePermModal();
            } catch (err) {
                console.error('保存权限失败:', err);
                window.alert('保存权限失败');
            } finally {
                if (permSave) permSave.disabled = false;
            }
        });
    }

    if (emojiPanel && emojiToggle) {
        EMOJI_LIST.forEach((emoji) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = emoji;
            b.addEventListener('click', (ev) => {
                ev.stopPropagation();
                messageInput.value += emoji;
                messageInput.focus();
            });
            emojiPanel.appendChild(b);
        });

        emojiToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = emojiPanel.hidden;
            emojiPanel.hidden = !open;
            emojiToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        emojiPanel.addEventListener('click', (e) => e.stopPropagation());

        document.addEventListener('click', () => {
            if (emojiPanel.hidden) return;
            emojiPanel.hidden = true;
            emojiToggle.setAttribute('aria-expanded', 'false');
        });
    }

    if (inputImage) {
        inputImage.addEventListener('change', () => {
            const f = inputImage.files && inputImage.files[0];
            inputImage.value = '';
            if (f) uploadAttachment(f);
        });
    }
    if (inputFile) {
        inputFile.addEventListener('change', () => {
            const f = inputFile.files && inputFile.files[0];
            inputFile.value = '';
            if (f) uploadAttachment(f);
        });
    }

    if (messagesContainer) {
        messagesContainer.addEventListener('click', (e) => {
            const imageEl = e.target && e.target.closest ? e.target.closest('img.message-image') : null;
            if (imageEl) {
                e.preventDefault();
                e.stopPropagation();
                const msgEl = imageEl.closest('.message');
                // 已展开操作菜单时，优先操作“撤回/回复”，不触发放大
                if (msgEl && msgEl.classList.contains('show-actions')) {
                    return;
                }
                // 触屏设备改为“双击放大”，降低滑动/误触触发概率
                if (isCoarsePointerDevice()) {
                    const mid = msgEl ? parseInt(msgEl.getAttribute('data-message-id') || '0', 10) : 0;
                    const now = Date.now();
                    const isSameMsg = mid > 0 && mid === lastImageTapMessageId;
                    const isQuickSecondTap = (now - lastImageTapAt) <= 320;
                    if (!isSameMsg || !isQuickSecondTap) {
                        lastImageTapAt = now;
                        lastImageTapMessageId = mid > 0 ? mid : 0;
                        return;
                    }
                    lastImageTapAt = 0;
                    lastImageTapMessageId = 0;
                }
                const src = imageEl.getAttribute('src') || '';
                if (src) {
                    openImagePreview(src);
                }
                return;
            }
            const recallBtn = e.target && e.target.closest ? e.target.closest('.message-recall-btn') : null;
            if (recallBtn) {
                e.preventDefault();
                e.stopPropagation();
                const msgEl = recallBtn.closest('.message');
                const mid = msgEl ? parseInt(msgEl.getAttribute('data-message-id') || '0', 10) : 0;
                if (mid > 0) {
                    recallMessage(mid);
                }
                return;
            }
            const replyBtn = e.target && e.target.closest ? e.target.closest('.message-reply-btn') : null;
            if (replyBtn) {
                e.preventDefault();
                e.stopPropagation();
                const msgEl = replyBtn.closest('.message');
                const mid = msgEl ? parseInt(msgEl.getAttribute('data-message-id') || '0', 10) : 0;
                const username = msgEl ? (msgEl.getAttribute('data-message-username') || '') : '';
                const preview = msgEl ? (msgEl.getAttribute('data-message-preview') || '') : '';
                if (mid > 0) {
                    setReplyTarget(mid, username, preview);
                    closeAllRecallActions();
                }
                return;
            }
            toggleRecallActionsByEvent(e);
        });

        // 移动端仅保留“长按展开操作”，避免与竖向滑动手势冲突
        messagesContainer.addEventListener('touchend', () => {
            if (touchRecallTimer) {
                clearTimeout(touchRecallTimer);
                touchRecallTimer = null;
            }
            touchRecallMoved = false;
        }, { passive: true });

        messagesContainer.addEventListener('touchstart', (e) => {
            const bubble = e.target && e.target.closest ? e.target.closest('.message-bubble') : null;
            if (!bubble) return;
            const msgEl = bubble.closest('.message');
            if (!msgEl) return;
            const canReply = msgEl.getAttribute('data-can-reply') === '1';
            const canRecall = msgEl.getAttribute('data-can-recall') === '1';
            if (!canRecall && !canReply) return;
            touchRecallMoved = false;
            if (touchRecallTimer) {
                clearTimeout(touchRecallTimer);
            }
            touchRecallTimer = setTimeout(() => {
                closeAllRecallActions(msgEl);
                msgEl.classList.add('show-actions');
                touchRecallTimer = null;
            }, 360);
        }, { passive: true });

        messagesContainer.addEventListener('touchmove', () => {
            touchRecallMoved = true;
            if (touchRecallTimer) {
                clearTimeout(touchRecallTimer);
                touchRecallTimer = null;
            }
        }, { passive: true });

        messagesContainer.addEventListener('touchcancel', () => {
            if (touchRecallTimer) {
                clearTimeout(touchRecallTimer);
                touchRecallTimer = null;
            }
            touchRecallMoved = false;
        }, { passive: true });
    }
});

document.addEventListener('click', (e) => {
    const inMyMenu = e.target && e.target.closest ? e.target.closest('.my-menu-wrap') : null;
    if (!inMyMenu) {
        closeMyMenu();
    }
    const replyCancelBtn = e.target && e.target.closest ? e.target.closest('#reply-cancel') : null;
    if (replyCancelBtn) {
        clearReplyTarget();
        return;
    }

    const inMessage = e.target && e.target.closest ? e.target.closest('.message') : null;
    const inReplyComposer = e.target && e.target.closest ? e.target.closest('#reply-box') : null;
    const inMessageInputArea = e.target && e.target.closest ? e.target.closest('.message-input') : null;
    if (!inReplyComposer && !inMessage && !inMessageInputArea && replyToMessageId > 0) {
        clearReplyTarget();
    }
    if (inMessage) return;
    closeAllRecallActions();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAllRecallActions();
        clearReplyTarget();
    }
});

function syncChatViewportHeight() {
    if (!document.body || !document.body.classList.contains('page-chat')) return;
    const viewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    const vh = Math.max(0, viewportHeight) * 0.01;
    document.documentElement.style.setProperty('--chat-vh', `${vh}px`);
}

if (messageInput) {
    messageInput.addEventListener('focus', () => {
        // 移动端键盘弹出后，保持视图定位在最新消息
        window.setTimeout(scrollToBottom, 30);
        window.setTimeout(scrollToBottom, 220);
    });
}

if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', () => {
        syncChatViewportHeight();
        if (document.activeElement === messageInput) {
            window.setTimeout(scrollToBottom, 0);
        }
    });
    window.visualViewport.addEventListener('scroll', syncChatViewportHeight);
}
window.addEventListener('resize', syncChatViewportHeight);
window.addEventListener('orientationchange', syncChatViewportHeight);
syncChatViewportHeight();

async function uploadAttachment(file) {
    if (document.body.dataset.canUpload === '0' || document.body.dataset.isMuted === '1') {
        return;
    }
    if (uploading || !roomId) return;
    uploading = true;
    try {
        const formData = new FormData();
        formData.append('room_id', String(roomId));
        formData.append('file', file);
        const cap = messageInput.value.trim();
        if (cap) formData.append('message', cap);
        const hadReplyTarget = replyToMessageId > 0;
        if (replyToMessageId > 0) formData.append('reply_to_message_id', String(replyToMessageId));
        if (hadReplyTarget) {
            clearReplyTarget();
        }

        const response = await fetch('api/send_message.php', {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            messageInput.value = '';
            clearReplyTarget();
            loadMessages();
        } else {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) {
                return;
            }
            const err = data.error || '发送失败';
            window.alert(err);
        }
    } catch (error) {
        console.error('上传失败:', error);
        window.alert('上传失败，请重试');
    } finally {
        uploading = false;
    }
}

async function loadMessages() {
    if (loadMessagesInFlight) {
        loadMessagesQueued = true;
        return;
    }
    loadMessagesInFlight = true;
    try {
        const response = await fetch(`api/get_messages.php?room_id=${roomId}&last_id=${lastMessageId}`);
        const data = await response.json();

        if (data.success === false) {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) {
                return;
            }
            if (data.error) {
                console.warn(data.error);
            }
            return;
        }

        const shouldAutoScroll = isNearBottom(88) || document.activeElement === messageInput;
        const msgs = data.messages || [];
        if (msgs.length > 0) {
            msgs.forEach((message) => {
                appendMessage(message);
                if (message.id > lastMessageId) {
                    lastMessageId = message.id;
                }
            });
            if (shouldAutoScroll) {
                scrollToBottom();
            }
        }
    } catch (error) {
        console.error('加载消息失败:', error);
    } finally {
        loadMessagesInFlight = false;
        if (loadMessagesQueued) {
            loadMessagesQueued = false;
            loadMessages();
        }
    }
}

async function loadOnlineUsers(isManualRefresh = false) {
    try {
        const response = await fetch(`api/get_users.php?room_id=${roomId}&last_update=${lastUserUpdate}`);
        const data = await response.json();

        if (data.success === false) {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) {
                return;
            }
            if (data.error) {
                console.warn(data.error);
            }
            return;
        }

        if (data.users) {
            updateUsersList(data.users, isManualRefresh);
            lastUserUpdate = data.timestamp;
        }
    } catch (error) {
        console.error('加载用户列表失败:', error);
    }
}

async function sendMessage(event) {
    event.preventDefault();

    if (document.body.dataset.isMuted === '1') {
        return;
    }

    if (uploading) return;

    const messageText = messageInput.value.trim();
    if (!messageText) return;

    try {
        const formData = new FormData();
        formData.append('room_id', String(roomId));
        formData.append('message', messageText);
        const hadReplyTarget = replyToMessageId > 0;
        if (replyToMessageId > 0) formData.append('reply_to_message_id', String(replyToMessageId));
        if (hadReplyTarget) {
            clearReplyTarget();
        }

        const response = await fetch('api/send_message.php', {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            messageInput.value = '';
            clearReplyTarget();
            loadMessages();
        } else {
            if (redirectIfRoomLocked(data) || redirectIfAccountFrozen(data)) {
                return;
            }
            if (data.error) {
                console.warn(data.error);
            }
        }
    } catch (error) {
        console.error('发送消息失败:', error);
    }
}

function appendMessage(message) {
    const mid = typeof message.id === 'number' ? message.id : 0;
    if (mid > 0 && messagesContainer) {
        if (messagesContainer.querySelector(`[data-message-id="${mid}"]`)) {
            return;
        }
    }

    const tsSec =
        typeof message.timestamp === 'number' && message.timestamp > 0 ? message.timestamp : 0;
    if (tsSec > 0) {
        maybeInsertMessageTimeSep(tsSec);
    }

    const messageElement = document.createElement('div');
    messageElement.classList.add('message');
    if (typeof message.id === 'number') {
        messageElement.setAttribute('data-message-id', String(message.id));
    }
    messageElement.setAttribute('data-message-kind', String(message.content_kind || 'text'));
    const kind = message.content_kind || 'text';
    const isRecalled = kind === 'recalled';
    const isSystem = Boolean(message.is_system);
    const isMine = Boolean(message.is_mine);
    const canReply = !isSystem && !isRecalled;
    const replyPreview =
        kind === 'image' ? '[图片]' :
        kind === 'file' ? '[文件]' :
        (isRecalled ? '消息已撤回' : String(message.content || '').replace(/\s+/g, ' ').trim());
    messageElement.setAttribute('data-can-reply', canReply ? '1' : '0');
    messageElement.setAttribute('data-message-username', String(message.username || (isMine ? '我' : '用户')));
    messageElement.setAttribute('data-message-preview', replyPreview.slice(0, 80));
    if (message && message.can_recall) {
        messageElement.setAttribute('data-can-recall', '1');
    } else {
        messageElement.setAttribute('data-can-recall', '0');
    }

    if (isSystem) {
        messageElement.classList.add('system');
    } else {
        messageElement.classList.add(isMine ? 'sent' : 'received');
    }

    const sender = document.createElement('div');
    sender.className = 'message-sender';
    if (isRecalled) {
        sender.textContent = '';
    } else if (!isSystem) {
        sender.textContent = '';
        const nameRow = document.createElement('div');
        nameRow.className = 'message-sender-name-row';
        const nameSpan = document.createElement('span');
        nameSpan.className = 'message-sender-name';
        nameSpan.textContent = message.username || '';
        nameRow.appendChild(nameSpan);
        sender.appendChild(nameRow);
        const isAdmin = document.body.dataset.isAdmin === '1';
        if (isAdmin) {
            const ip = message && message.sender_ip ? String(message.sender_ip).trim() : '';
            const geo = message && message.sender_geo_text ? String(message.sender_geo_text).trim() : '';
            if (ip !== '' || geo !== '') {
                sender.classList.add('message-sender--with-meta');
                const metaRow = document.createElement('div');
                metaRow.className = 'message-sender-meta-row';
                const meta = document.createElement('span');
                meta.className = 'message-sender-meta';
                meta.textContent = geo ? `${ip} · ${geo}` : ip;
                metaRow.appendChild(meta);
                sender.appendChild(metaRow);
            }
        }
    } else if (isSystem) {
        sender.textContent = '系统';
    } else {
        sender.textContent = '';
    }

    const content = document.createElement('div');
    content.className = 'message-content';
    let replyBlock = null;
    if (message.reply && typeof message.reply === 'object') {
        replyBlock = document.createElement('div');
        replyBlock.className = 'message-reply-snippet';
        const replyName = document.createElement('div');
        replyName.className = 'message-reply-snippet-name';
        replyName.textContent = String(message.reply.username || '用户');
        const replyContent = document.createElement('div');
        replyContent.className = 'message-reply-snippet-content';
        replyContent.textContent = String(message.reply.content || '原消息');
        replyBlock.appendChild(replyName);
        replyBlock.appendChild(replyContent);
    }

    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';

    if (isRecalled) {
        messageElement.classList.add('system');
        messageElement.classList.add('recalled');
        bubble.classList.add('message-bubble--recalled');
        const who = (typeof message.recalled_by_name === 'string' && message.recalled_by_name.trim() !== '')
            ? message.recalled_by_name.trim()
            : (Boolean(message.recalled_by_is_admin) ? '管理员' : '用户');
        content.textContent = `${who}撤回了一条消息`;
    } else if (isSystem) {
        content.innerHTML = message.content;
    } else if (kind === 'image' && message.attachment_url) {
        bubble.classList.add('message-bubble--image');
        const img = document.createElement('img');
        img.className = 'message-image';
        img.src = message.attachment_url;
        img.alt = '图片';
        img.title = '点击放大查看';
        img.loading = 'lazy';
        img.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openImagePreview(message.attachment_url);
        });
        content.appendChild(img);
        if (message.content && String(message.content).trim() !== '') {
            const cap = document.createElement('div');
            cap.className = 'message-caption';
            cap.innerHTML = message.content;
            content.appendChild(cap);
        }
    } else if (kind === 'file' && message.attachment_url) {
        bubble.classList.add('message-bubble--file');
        const card = document.createElement('div');
        card.className = 'message-file-card';

        const head = document.createElement('div');
        head.className = 'message-file-card-head';
        const label = document.createElement('span');
        label.className = 'message-file-card-label';
        label.textContent = '文件';
        head.appendChild(label);

        const body = document.createElement('div');
        body.className = 'message-file-card-body';
        const link = document.createElement('a');
        link.className = 'message-file-link';
        link.href = message.attachment_url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        const name = message.attachment_name || '下载文件';
        link.setAttribute('download', name);
        link.setAttribute('aria-label', '下载：' + name);
        const icon = document.createElement('span');
        icon.className = 'message-file-link-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML =
            '<svg class="message-file-svg" width="22" height="28" viewBox="0 0 24 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v24a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10l-8-8z" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-width="1.5"/><path d="M14 2v8h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        const nameSpan = document.createElement('span');
        nameSpan.className = 'message-file-link-name';
        nameSpan.textContent = name;
        link.appendChild(icon);
        link.appendChild(nameSpan);
        body.appendChild(link);
        card.appendChild(head);
        card.appendChild(body);

        if (message.content && String(message.content).trim() !== '') {
            const cap = document.createElement('div');
            cap.className = 'message-caption';
            cap.innerHTML = message.content;
            card.appendChild(cap);
        }
        content.appendChild(card);
    } else {
        content.innerHTML = message.content;
    }
    if (replyBlock && !isRecalled && !isSystem) {
        content.appendChild(replyBlock);
    }

    bubble.appendChild(content);

    if (isRecalled) {
        // 撤回提示行不显示“系统”抬头
    } else if (!isSystem && message.username) {
        messageElement.appendChild(sender);
    } else if (isSystem) {
        messageElement.appendChild(sender);
    }

    messageElement.appendChild(bubble);

    if (!isSystem && (canReply || (message && message.can_recall))) {
        const actions = document.createElement('div');
        actions.className = 'message-actions';
        if (canReply) {
            const replyBtn = document.createElement('button');
            replyBtn.type = 'button';
            replyBtn.className = 'message-reply-btn';
            replyBtn.textContent = '回复';
            actions.appendChild(replyBtn);
        }
        if (message && message.can_recall) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'message-recall-btn';
            btn.textContent = '撤回';
            actions.appendChild(btn);
        }
        messageElement.appendChild(actions);
    }

    messagesContainer.appendChild(messageElement);

    if (tsSec > 0) {
        prevMessageTs = tsSec;
    }
}

function updateUsersList(users, isManualRefresh = false) {
    usersList.innerHTML = '';

    const isAdmin = document.body.dataset.isAdmin === '1';
    const onlineUsers = users.filter((user) => user.online);

    onlineUsers.forEach((user) => {
        const userElement = document.createElement('li');
        const label = user.display_label || user.username || '';
        const uid = Number(user.user_id || 0);

        const avatar = document.createElement('div');
        avatar.className = 'user-avatar';
        const letter = label.charAt(0).toUpperCase();
        avatar.textContent = letter;

        const span = document.createElement('span');
        span.textContent = label;

        userElement.appendChild(avatar);
        userElement.appendChild(span);

        if (isAdmin && (user.ip || user.geo_text)) {
            const meta = document.createElement('div');
            meta.className = 'online-user-meta';
            const ip = user.ip ? String(user.ip) : '';
            const geo = user.geo_text ? String(user.geo_text) : '';
            meta.textContent = geo ? `${ip} · ${geo}` : ip;
            userElement.appendChild(meta);
        }
        if (isAdmin && uid > 0) {
            userElement.classList.add('online-user--editable');
            userElement.title = '点击配置权限';
            userElement.addEventListener('click', () => {
                openUserPermissions(uid, label);
            });
        }
        usersList.appendChild(userElement);
    });

    if (onlineCountEl) {
        onlineCountEl.textContent = String(onlineUsers.length);
    }

    if (isManualRefresh) {
        showRefreshNotification(true, onlineUsers.length);
    }
}

function showRefreshNotification(success, userCount) {
    let notification = document.getElementById('refresh-notification');
    if (!notification) {
        notification = document.createElement('div');
        notification.id = 'refresh-notification';
        notification.classList.add('glass-effect');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 10px 20px;
            border-radius: 8px;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        `;
        document.body.appendChild(notification);
    }

    notification.textContent = success ? `刷新成功，当前共有 ${userCount} 人在线` : '刷新失败';
    notification.style.backgroundColor = success ? '#e8f0e4' : '#f5e8e4';
    notification.style.color = success ? '#3d5c38' : '#7a3c32';
    notification.style.border = success ? '1px solid #a8c4a0' : '1px solid #d4a89a';
    notification.style.opacity = '1';

    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function isNearBottom(threshold = 64) {
    if (!messagesContainer) return true;
    const remain = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight;
    return remain <= threshold;
}

function scrollToBottom() {
    if (!messagesContainer) return;
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}
