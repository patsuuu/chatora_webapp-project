<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Chatora | Web Messages</title>
  <link rel="icon" href="logo.png" type="image/png" />
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="chat-app">
    <header class="chat-header">
      <div class="chat-header-left">
        <div>
          <h1>
          <span class="brand">
            <a class="brand-link" href="index.php">
              <img class="brand-logo" src="logo.png" alt="Chatora logo">
            </a>
            <span class="brand-name">Chatora</span>
          </span>
        </h1>
          <p></p>
        </div>
      </div>
      <div class="chat-header-right">
        <div id="account-panel" class="header-account-card">
          <button id="account-button" type="button" class="account-button">
            <span class="account-icon" aria-hidden="true">⌖</span>
            <span class="account-label"><strong id="account-name"></strong></span>
            <span class="account-chevron" aria-hidden="true">▾</span>
          </button>
          <div id="account-menu" class="account-menu hidden">
            <?php if (!empty($_SESSION['is_admin'])): ?>
              <a href="admin.php" class="dropdown-button" style="text-decoration:none; display:block;">Admin Panel</a>
            <?php endif; ?>
            <button id="logout-button" type="button" class="dropdown-button">Logout</button>
          </div>
        </div>
        <div class="chat-status header-chat-status">
          <span id="status-dot" class="status-dot"></span>
          <div>
            <div id="status-text">Connecting...</div>
            <div id="message-notice" class="message-notice hidden"></div>
          </div>
        </div>
      </div>
    </header>

    <section class="chat-main">
      <div class="chat-sidebar">
        <div class="friends-panel">
          <div class="panel-heading">
            <h3>Friends</h3>
            <span class="friends-count" id="friends-count">0</span>
          </div>
          <div id="friend-list" class="friend-list"></div>
          <div class="pending-panel">
            <h4>Pending Requests</h4>
            <div id="friend-requests" class="request-list"></div>
          </div>
          <div class="friend-add">
            <input id="friend-identifier" type="text" placeholder="Add friend by username or ID" maxlength="50" />
            <button id="add-friend-button" type="button">Add</button>
          </div>
        </div>
      </div>

      <div class="chat-window">
        <div id="messages" class="messages"></div>
        <div id="no-chat-selected" class="login-required">Pumili ng kaibigan sa iyong chat list para mag-simula ng usapan.</div>
        <div id="chat-meta" class="chat-meta hidden">
          <div class="chat-meta-title"><span id="chat-partner-status" class="online-dot offline"></span> Chat kay <strong id="chat-partner-name"></strong></div>
        </div>
        <p id="login-required" class="login-required">Mangyaring mag-login o mag-register muna bago mag-chat.</p>
        <form id="chat-form" class="chat-form">
          <div class="chat-form-row">
            <button id="image-button" type="button" class="image-button" disabled title="Send Image">+</button>
            <input id="message-input" type="text" placeholder="Type a message..." maxlength="255" autocomplete="off" disabled />
            <button type="submit" disabled>Send</button>
          </div>
          <input id="image-input" type="file" accept="image/*" disabled style="display: none;" />
        </form>
      </div>
    </section>
  </div>

  <script>
// Inlined chat.js to avoid hosting 403 for external JS. Temporary for debugging.
const messagesEl = document.getElementById('messages');
const chatForm = document.getElementById('chat-form');
const messageInput = document.getElementById('message-input');
const imageInput = document.getElementById('image-input');
const imageButton = document.getElementById('image-button');
const statusDot = document.getElementById('status-dot');
const statusText = document.getElementById('status-text');
const logoutButton = document.getElementById('logout-button');
const accountButton = document.getElementById('account-button');
const accountMenu = document.getElementById('account-menu');
const accountNameEl = document.getElementById('account-name');
const loginRequiredEl = document.getElementById('login-required');
const friendListEl = document.getElementById('friend-list');
const friendIdentifierInput = document.getElementById('friend-identifier');
const addFriendButton = document.getElementById('add-friend-button');
const noChatSelectedEl = document.getElementById('no-chat-selected');
const chatMetaEl = document.getElementById('chat-meta');
const chatPartnerNameEl = document.getElementById('chat-partner-name');
const messageNoticeEl = document.getElementById('message-notice');
const friendRequestsEl = document.getElementById('friend-requests');

const MESSAGES_FETCH_INTERVAL = 1000;
const FRIEND_STATUS_REFRESH_INTERVAL = 3000;
const HEARTBEAT_INTERVAL = 10000;
let lastMessages = [];
let chatInterval = null;
let friendStatusInterval = null;
let heartbeatInterval = null;
let accountLoggedIn = false;
let currentChatUserId = null;
let currentFriends = [];
let incomingRequests = [];
let outgoingRequests = [];
let unreadCounts = {};

function startPolling() {
  if (!chatInterval) {
    chatInterval = setInterval(fetchMessages, MESSAGES_FETCH_INTERVAL);
  }
  if (!friendStatusInterval) {
    friendStatusInterval = setInterval(() => {
      if (accountLoggedIn) {
        loadFriendList();
      }
    }, FRIEND_STATUS_REFRESH_INTERVAL);
  }
  if (!heartbeatInterval) {
    heartbeatInterval = setInterval(() => {
      if (accountLoggedIn) {
        sendHeartbeat();
      }
    }, HEARTBEAT_INTERVAL);
  }
}

function stopPolling() {
  if (chatInterval) {
    clearInterval(chatInterval);
    chatInterval = null;
  }
  if (friendStatusInterval) {
    clearInterval(friendStatusInterval);
    friendStatusInterval = null;
  }
  if (heartbeatInterval) {
    clearInterval(heartbeatInterval);
    heartbeatInterval = null;
  }
}

function setStatus(online) {
  statusDot.style.background = online ? 'var(--success)' : 'var(--muted)';
  statusText.textContent = online ? 'Online' : 'Disconnected';
}

function renderMessages(messages) {
  if (!Array.isArray(messages)) return;
  const changed = JSON.stringify(messages) !== JSON.stringify(lastMessages);
  if (!changed) return;

  lastMessages = messages;
  const currentUsername = accountNameEl.textContent;
  messagesEl.innerHTML = messages
    .map(msg => {
      const time = formatMessageTimestamp(msg.created_at || msg.timestamp);
      const text = msg.text || '';
      const isOwnMessage = msg.author === currentUsername;
      let content = '';
      if (text.trim()) {
        content += `<div class="text">${escapeHtml(text)}</div>`;
      }
      if (msg.image && msg.image.data) {
        content += `
          <div class="image-message">
            <img src="data:${escapeHtml(msg.image.mime)};base64,${msg.image.data}" alt="${escapeHtml(msg.image.filename)}" />
          </div>
        `;
      }
      if (!content) {
        content = '<div class="text">(No message content)</div>';
      }
      const actions = `
        <div class="message-actions">
          <button class="message-action-ellipsis" aria-haspopup="true" aria-expanded="false" data-message-id="${msg.id}" title="More options" aria-label="More options">⋮</button>
          <div class="message-menu hidden" data-message-id="${msg.id}">
            <button class="message-menu-item" data-action="me" data-message-id="${msg.id}">Delete for me</button>
            ${isOwnMessage ? `<button class="message-menu-item" data-action="everyone" data-message-id="${msg.id}">Delete for everyone</button>` : ''}
          </div>
        </div>
      `;
      return `
        <div class="message">
          <div class="meta"><strong>${escapeHtml(msg.author)}</strong></div>
          ${actions}
          ${content}
          <div class="message-time">${time}</div>
        </div>
      `;
    })
    .join('');

  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function escapeHtml(value) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatMessageTimestamp(value) {
  if (!value) return '';
  const numericValue = Number(value);
  const date = Number.isFinite(numericValue)
    ? new Date(numericValue < 1e12 ? numericValue * 1000 : numericValue)
    : new Date(value);

  if (Number.isNaN(date.getTime())) {
    const fallbackDate = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(fallbackDate.getTime())) {
      return String(value);
    }
    return formatDateParts(fallbackDate);
  }

  return formatDateParts(date);
}

function formatDateParts(date) {
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${day}/${month}/${year} ${hours}:${minutes}`;
}

async function parseJsonSafe(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (error) {
    console.error('Invalid JSON from', response.url, response.status, text);
    throw new Error('Invalid server response. Check console for details.');
  }
}

function setAccountState(loggedIn, username) {
  accountLoggedIn = loggedIn;
  accountNameEl.textContent = username;

  if (loggedIn) {
    loginRequiredEl.classList.add('hidden');
    const canChat = currentChatUserId !== null;
    messageInput.disabled = !canChat;
    imageInput.disabled = !canChat;
    imageButton.disabled = !canChat;
    chatForm.querySelector('button[type="submit"]').disabled = !canChat;
    messageInput.placeholder = canChat ? 'Select a friend to message.' : 'Select a friend first.';
  } else {
    clearClientState();
    loginRequiredEl.classList.remove('hidden');
    messageInput.disabled = true;
    imageInput.disabled = true;
    imageButton.disabled = true;
    chatForm.querySelector('button[type="submit"]').disabled = true;
    messageInput.placeholder = 'Login first to send messages.';
  }
  updateChatVisibility();
}

async function handleAuth(action) {
  if (action === 'logout') {
    try {
      const response = await fetch('auth.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'logout' }),
      });
      const result = await parseJsonSafe(response);
      if (result.success) {
        notifyOffline();
        stopPolling();
        window.location.href = 'login.php';
      } else {
        console.error('Logout failed', result);
      }
    } catch (error) {
      console.error('Logout failed', error);
    }
  }
}

async function fetchAuthStatus() {
  try {
    const response = await fetch('auth.php?action=status', {
      credentials: 'same-origin',
    });
    if (!response.ok) throw new Error('Unable to load account status.');
    const data = await parseJsonSafe(response);
    if (!data.success || !data.loggedIn) {
      window.location.href = 'login.php';
      return;
    }
    setAccountState(true, data.username || '');
    await loadFriendList();
    startPolling();
  } catch (error) {
    console.error(error);
  }
}

function updateChatVisibility() {
  const chatEnabled = accountLoggedIn && currentChatUserId !== null;
  noChatSelectedEl.classList.toggle('hidden', chatEnabled);
  chatMetaEl.classList.toggle('hidden', !chatEnabled);
  loginRequiredEl.classList.toggle('hidden', accountLoggedIn);
  messageInput.disabled = !chatEnabled;
  imageButton.disabled = !chatEnabled;
  imageInput.disabled = !chatEnabled;
  chatForm.querySelector('button[type="submit"]').disabled = !chatEnabled;
}

function clearClientState() {
  currentFriends = [];
  unreadCounts = {};
  currentChatUserId = null;
  lastMessages = [];
  messagesEl.innerHTML = '';
  renderFriendList();
  updateMessageNotice();
  setStatus(false);
  updateChatVisibility();
}

function updateSelectedPartnerStatus() {
  if (currentChatUserId === null) {
    return;
  }
  const partner = currentFriends.find(friend => friend.id === currentChatUserId);
  if (!partner) {
    return;
  }
  const partnerStatusEl = document.getElementById('chat-partner-status');
  if (partnerStatusEl) {
    partnerStatusEl.classList.toggle('online', partner.online);
    partnerStatusEl.classList.toggle('offline', !partner.online);
    partnerStatusEl.title = partner.online ? 'Online' : 'Offline';
  }
}

function createFriendCard(friend) {
  const card = document.createElement('div');
  card.className = 'friend-card';

  const main = document.createElement('div');
  main.className = 'friend-card-main';
  const status = friend.online ? 'online' : 'offline';
  const statusDot = `<span class="online-dot ${status}" title="${status}"></span>`;
  main.innerHTML = `
    <div class="friend-card-title">${statusDot}<strong>${escapeHtml(friend.username)}</strong></div>
    <div class="friend-meta">ID: ${friend.id}</div>
  `;
  card.appendChild(main);

  const actions = document.createElement('div');
  actions.className = 'friend-actions';

  if (friend.unreadCount > 0) {
    const badge = document.createElement('span');
    badge.className = 'friend-badge';
    badge.textContent = friend.unreadCount;
    actions.appendChild(badge);
  }

  if (!friend.isAdmin) {
    const unfriend = document.createElement('button');
    unfriend.type = 'button';
    unfriend.className = 'secondary-button unfriend-button';
    unfriend.textContent = 'Unfriend';
    unfriend.addEventListener('click', event => {
      event.stopPropagation();
      unfriendUser(friend.id);
    });
    actions.appendChild(unfriend);
  } else {
    const adminLabel = document.createElement('span');
    adminLabel.className = 'friend-admin-label';
    adminLabel.textContent = 'Admin';
    actions.appendChild(adminLabel);
  }

  card.appendChild(actions);
  card.addEventListener('click', () => selectFriend(friend));
  return card;
}

function renderFriendList() {
  friendListEl.innerHTML = '';
  if (currentFriends.length === 0) {
    friendListEl.innerHTML = '<div class="login-required">Walang friend list. Magdagdag ng kaibigan gamit ang ID o username.</div>';
    updateFriendCount();
    return;
  }

  currentFriends.forEach(friend => {
    const friendWithUnread = Object.assign({}, friend, {
      unreadCount: unreadCounts[friend.id] || 0,
    });
    friendListEl.appendChild(createFriendCard(friendWithUnread));
  });
  updateFriendCount();
}

function updateFriendCount() {
  const countEl = document.getElementById('friends-count');
  if (countEl) {
    countEl.textContent = currentFriends.length.toString();
  }
}

function createFriendRequestCard(request, type) {
  const card = document.createElement('div');
  card.className = 'request-card';

  const title = document.createElement('div');
  title.className = 'request-card-main';
  title.innerHTML = `
    <strong>${escapeHtml(type === 'incoming' ? request.senderName : request.receiverName)}</strong>
    <div class="request-meta">${type === 'incoming' ? 'Incoming request' : 'Outgoing request'}</div>
  `;
  card.appendChild(title);

  if (type === 'incoming') {
    const actions = document.createElement('div');
    actions.className = 'request-actions';

    const acceptButton = document.createElement('button');
    acceptButton.type = 'button';
    acceptButton.className = 'secondary-button';
    acceptButton.textContent = 'Accept';
    acceptButton.addEventListener('click', event => {
      event.stopPropagation();
      respondToRequest(request.requestId, 'accept');
    });

    const rejectButton = document.createElement('button');
    rejectButton.type = 'button';
    rejectButton.className = 'secondary-button';
    rejectButton.textContent = 'Reject';
    rejectButton.addEventListener('click', event => {
      event.stopPropagation();
      respondToRequest(request.requestId, 'reject');
    });

    actions.appendChild(acceptButton);
    actions.appendChild(rejectButton);
    card.appendChild(actions);
  } else {
    const status = document.createElement('div');
    status.className = 'request-status';
    status.textContent = 'Pending';
    card.appendChild(status);
  }

  return card;
}

function renderFriendRequests() {
  friendRequestsEl.innerHTML = '';

  if (incomingRequests.length === 0 && outgoingRequests.length === 0) {
    friendRequestsEl.innerHTML = '<div class="login-required">Walang pending requests.</div>';
    return;
  }

  if (incomingRequests.length > 0) {
    const header = document.createElement('div');
    header.className = 'request-section-title';
    header.textContent = 'Incoming Requests';
    friendRequestsEl.appendChild(header);
    incomingRequests.forEach(request => {
      friendRequestsEl.appendChild(createFriendRequestCard(request, 'incoming'));
    });
  }

  if (outgoingRequests.length > 0) {
    const header = document.createElement('div');
    header.className = 'request-section-title';
    header.textContent = 'Outgoing Requests';
    friendRequestsEl.appendChild(header);
    outgoingRequests.forEach(request => {
      friendRequestsEl.appendChild(createFriendRequestCard(request, 'outgoing'));
    });
  }
}

function updateMessageNotice() {
  const totalUnread = Object.values(unreadCounts).reduce((sum, count) => sum + count, 0);
  if (totalUnread > 0) {
    messageNoticeEl.textContent = totalUnread === 1 ? 'May 1 bagong mensahe' : `May ${totalUnread} bagong mensahe`;
    messageNoticeEl.classList.remove('hidden');
  } else {
    messageNoticeEl.classList.add('hidden');
  }
}

function selectFriend(friend) {
  console.log('selectFriend', friend);
  currentChatUserId = friend.id;
  chatPartnerNameEl.textContent = friend.username;
  const partnerStatusEl = document.getElementById('chat-partner-status');
  if (partnerStatusEl) {
    partnerStatusEl.classList.toggle('online', friend.online);
    partnerStatusEl.classList.toggle('offline', !friend.online);
    partnerStatusEl.title = friend.online ? 'Online' : 'Offline';
  }
  chatMetaEl.classList.remove('hidden');
  noChatSelectedEl.classList.add('hidden');
  loginRequiredEl.classList.toggle('hidden', accountLoggedIn);
  updateChatVisibility();
  loadConversation(friend.id);
}

async function loadFriendList() {
  try {
    const response = await fetch('friends.php?action=list', {
      credentials: 'same-origin',
    });
    const text = await response.text();
    if (!response.ok) {
      console.error('Friends list fetch failed', response.status, text);
      throw new Error('Unable to load friends.');
    }
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseError) {
      console.error('Error parsing friends list JSON', text);
      throw new Error('Invalid server response while loading friends.');
    }
    if (!data.success) {
      console.error('Friends list error response', data);
      throw new Error(data.error || 'Unable to load friends.');
    }

    currentFriends = data.friends;
    renderFriendList();
    updateSelectedPartnerStatus();
    await fetchUnreadCounts();
    await loadFriendRequests();
  } catch (error) {
    console.error('loadFriendList failed', error);
    if (accountLoggedIn) {
      friendListEl.innerHTML = '<div class="login-required">Failed to load friends.</div>';
    }
  }
}

async function addFriend() {
  const identifier = friendIdentifierInput.value.trim();
  if (!identifier) {
    alert('Enter a friend ID or username.');
    return;
  }

  try {
    const response = await fetch('friends.php?action=add', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'add', identifier }),
    });
    if (!response.ok) throw new Error('Unable to send friend request.');
    const data = await parseJsonSafe(response);
    if (!data.success) {
      alert(data.error || 'Unable to send friend request.');
      return;
    }

    friendIdentifierInput.value = '';
    await loadFriendRequests();
  } catch (error) {
    console.error(error);
    alert(error.message);
  }
}

async function unfriendUser(friendId) {
  if (!confirm('Are you sure you want to unfriend this person?')) {
    return;
  }

  try {
    const response = await fetch('friends.php?action=remove', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'remove', friendId }),
    });
    if (!response.ok) throw new Error('Unable to remove friend.');
    const data = await parseJsonSafe(response);
    if (!data.success) {
      alert(data.error || 'Unable to remove friend.');
      return;
    }

    if (currentChatUserId === friendId) {
      currentChatUserId = null;
      noChatSelectedEl.classList.remove('hidden');
      chatMetaEl.classList.add('hidden');
      messagesEl.innerHTML = '';
    }

    await loadFriendList();
  } catch (error) {
    console.error(error);
    alert(error.message);
  }
}

async function loadFriendRequests() {
  try {
    const response = await fetch('friends.php?action=pending', {
      credentials: 'same-origin',
    });
    if (!response.ok) throw new Error('Unable to load friend requests.');
    const data = await parseJsonSafe(response);
    if (!data.success) throw new Error(data.error || 'Unable to load friend requests.');

    incomingRequests = data.incoming || [];
    outgoingRequests = data.outgoing || [];
    renderFriendRequests();
  } catch (error) {
    console.error(error);
    if (friendRequestsEl) {
      friendRequestsEl.innerHTML = '<div class="login-required">Failed to load requests.</div>';
    }
  }
}

async function respondToRequest(requestId, decision) {
  try {
    const response = await fetch('friends.php?action=respond', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'respond', requestId, decision }),
    });
    if (!response.ok) throw new Error('Unable to respond to request.');
    const data = await parseJsonSafe(response);
    if (!data.success) {
      alert(data.error || 'Unable to respond to friend request.');
      return;
    }

    await loadFriendRequests();
    await loadFriendList();
  } catch (error) {
    console.error(error);
    alert(error.message);
  }
}

async function loadConversation(otherId) {
  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'messages', otherId }),
    });
    if (!response.ok) throw new Error('Unable to load conversation.');
    const data = await parseJsonSafe(response);
    if (!data.success) throw new Error(data.error || 'Unable to load messages.');
    renderMessages(data.messages.map(msg => ({
      id: msg.id,
      author: msg.sender,
      text: msg.text,
      created_at: msg.created_at,
      timestamp: msg.created_at,
      image: msg.image || null,
    })));
    await markMessagesRead(otherId);
    setStatus(true);
  } catch (error) {
    setStatus(false);
    console.error(error);
  }
}

async function fetchMessages() {
  if (!accountLoggedIn || currentChatUserId === null) {
    return;
  }

  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'messages', otherId: currentChatUserId }),
    });
    if (!response.ok) throw new Error('Network error');
    const data = await parseJsonSafe(response);
    if (data.success) {
      renderMessages(data.messages.map(msg => ({
        id: msg.id,
        author: msg.sender,
        text: msg.text,
        timestamp: new Date(msg.created_at).getTime() / 1000,
        image: msg.image || null,
      })));
      setStatus(true);
    } else {
      throw new Error(data.error || 'Unable to fetch messages.');
    }
  } catch (error) {
    setStatus(false);
    console.error('Fetch failed', error);
  }
}

async function markMessagesRead(otherId) {
  try {
    await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', otherId }),
    });
    unreadCounts[otherId] = 0;
    renderFriendList();
    updateMessageNotice();
  } catch (error) {
    console.error('Unable to mark messages read.', error);
  }
}

async function fetchUnreadCounts() {
  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'unread' }),
    });
    if (!response.ok) throw new Error('Unable to fetch unread counts.');
    const data = await parseJsonSafe(response);
    if (!data.success) throw new Error(data.error || 'Unable to fetch unread counts.');

    unreadCounts = {};
    data.unread.forEach(entry => {
      unreadCounts[entry.friendId] = entry.count;
    });
    renderFriendList();
    updateMessageNotice();
  } catch (error) {
    console.error(error);
  }
}

async function sendMessage(text) {
  console.log('sendMessage called', { text, currentChatUserId, accountLoggedIn });
  if (!accountLoggedIn || currentChatUserId === null) {
    alert('Please select a friend and login first to chat.');
    return;
  }

  if (!text.trim()) return;

  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'send', otherId: currentChatUserId, text }),
    });

    const rawText = await response.text();
    console.log('sendMessage response', response.status, rawText);
    if (!response.ok) {
      console.error('Send message failed HTTP', response.status, rawText);
      throw new Error('Unable to send message due to network/server error.');
    }

    let result;
    try {
      result = JSON.parse(rawText);
    } catch (parseError) {
      console.error('Send message invalid JSON', rawText);
      throw new Error('Unable to send message: invalid server response.');
    }

    if (result.success) {
      messageInput.value = '';
      loadConversation(currentChatUserId);
    } else {
      alert(result.error || 'Unable to send message.');
      console.error('Send message error response', result);
    }
  } catch (error) {
    setStatus(false);
    console.error('Send failed', error);
    alert(error.message || 'Failed to send message. Check console for details.');
  }
}

async function sendImage() {
  if (!accountLoggedIn || currentChatUserId === null) {
    alert('Please select a friend and login first to chat.');
    return;
  }

  const file = imageInput.files[0];
  if (!file) {
    alert('Please choose an image to send.');
    return;
  }

  console.log('Sending image:', { fileName: file.name, fileSize: file.size, fileType: file.type });

  const formData = new FormData();
  formData.append('action', 'send_image');
  formData.append('otherId', currentChatUserId);
  formData.append('image', file);

  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    });

    const rawText = await response.text();
    console.log('Send image response:', response.status, rawText);
    
    if (!response.ok) {
      console.error('Send image failed HTTP', response.status, rawText);
      throw new Error('Unable to send image due to network/server error.');
    }

    let result;
    try {
      result = JSON.parse(rawText);
    } catch (parseError) {
      console.error('Send image invalid JSON', rawText);
      throw new Error('Unable to send image: invalid server response.');
    }

    if (result.success) {
      imageInput.value = '';
      loadConversation(currentChatUserId);
    } else {
      alert(result.error || 'Unable to send image.');
      console.error('Send image error response', result);
    }
  } catch (error) {
    setStatus(false);
    console.error('Send image failed', error);
    alert(error.message || 'Failed to send image. Check console for details.');
  }
}

async function deleteMessage(messageId, mode) {
  if (!messageId) return;
  const confirmation = mode === 'everyone'
    ? confirm('Delete this message for everyone? This cannot be undone.')
    : confirm('Delete this message for your view only?');
  if (!confirmation) return;

  try {
    const response = await fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', messageId: parseInt(messageId, 10), mode }),
    });
    if (!response.ok) throw new Error('Server error');
    const result = await parseJsonSafe(response);
    if (result.success) {
      if (currentChatUserId !== null) {
        loadConversation(currentChatUserId);
      }
    } else {
      alert(result.error || 'Unable to delete message.');
    }
  } catch (error) {
    console.error('Delete message failed', error);
  }
}

// Message menu handling (open/close & action selection)
messagesEl.addEventListener('click', event => {
  const target = event.target;

  // Open/close ellipsis menu
  if (target.matches('.message-action-ellipsis')) {
    const messageId = target.dataset.messageId;
    // close any open menus first
    document.querySelectorAll('.message-menu').forEach(m => m.classList.add('hidden'));
    const menus = messagesEl.querySelectorAll(`.message-menu[data-message-id="${messageId}"]`);
    menus.forEach(menu => menu.classList.toggle('hidden'));
    return;
  }

  // Click on a menu item
  if (target.matches('.message-menu-item')) {
    const messageId = target.dataset.messageId;
    const action = target.dataset.action; // 'me' or 'everyone'
    if (action === 'me') {
      deleteMessage(messageId, 'me');
    } else if (action === 'everyone') {
      deleteMessage(messageId, 'everyone');
    }
    return;
  }
});

// Close menus when clicking outside
document.addEventListener('click', (e) => {
  const ellipsis = e.target.closest('.message-action-ellipsis');
  const menu = e.target.closest('.message-menu');
  if (!ellipsis && !menu) {
    document.querySelectorAll('.message-menu').forEach(m => m.classList.add('hidden'));
  }
});

chatForm.addEventListener('submit', event => {
  event.preventDefault();
  const text = messageInput.value;
  sendMessage(text);
});

imageButton.addEventListener('click', (event) => {
  event.preventDefault();
  imageInput.click();
});

imageInput.addEventListener('change', () => {
  sendImage();
});

window.addEventListener('beforeunload', () => {
  notifyOffline();
});

window.addEventListener('unload', () => {
  notifyOffline();
});

async function sendHeartbeat() {
  try {
    await fetch('auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'heartbeat' }),
    });
  } catch (error) {
    console.error('Heartbeat failed', error);
  }
}

function notifyOffline() {
  if (!accountLoggedIn) {
    return;
  }

  const payload = { action: 'offline' };
  if (navigator.sendBeacon) {
    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
    navigator.sendBeacon('auth.php', blob);
  } else {
    fetch('auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
      body: JSON.stringify(payload),
    }).catch(() => {});
  }
}

addFriendButton.addEventListener('click', addFriend);
logoutButton.addEventListener('click', () => handleAuth('logout'));
accountButton.addEventListener('click', () => {
  accountMenu.classList.toggle('hidden');
});

document.addEventListener('click', event => {
  if (!accountButton.contains(event.target) && !accountMenu.contains(event.target)) {
    accountMenu.classList.add('hidden');
  }
});

fetchAuthStatus();
startPolling();

window.addEventListener('focus', () => {
  if (accountLoggedIn) loadFriendList();
});
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && accountLoggedIn) {
    loadFriendList();
    loadFriendRequests();
  }
});
  </script>
</body>
</html>
