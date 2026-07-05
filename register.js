const accountUsernameInput = document.getElementById('account-username');
const accountPasswordInput = document.getElementById('account-password');
const registerButton = document.getElementById('register-button');
const authForm = document.getElementById('auth-form');

async function parseJsonSafe(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (error) {
    console.error('Invalid JSON from', response.url, response.status, text);
    throw new Error('Invalid server response. Check console for details.');
  }
}

async function postAuth(action, username, password) {
  const response = await fetch('auth.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, username, password }),
  });
  if (!response.ok) {
    throw new Error('Registration request failed.');
  }
  return parseJsonSafe(response);
}

async function handleRegister() {
  const username = accountUsernameInput.value.trim();
  const password = accountPasswordInput.value;

  if (!username || !password) {
    alert('Please provide a username and password.');
    return;
  }

  try {
    const result = await postAuth('register', username, password);
    if (!result.success) {
      alert(result.error || 'Unable to register.');
      return;
    }
    alert('Registration successful. Please login to continue.');
    window.location.href = 'login.php';
  } catch (error) {
    console.error(error);
    alert(error.message);
  }
}

authForm.addEventListener('submit', event => {
  event.preventDefault();
  handleRegister();
});
