const api = {
  async request(path, options = {}) {
    const response = await fetch(path, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = data && data.error ? data.error : `HTTP ${response.status}`;
      throw new Error(message);
    }
    return data;
  },
  getUsers() {
    return this.request('/api/users.php');
  },
  createUser(name) {
    return this.request('/api/users.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name }),
    });
  },
  detectKeywords(title, comment) {
    return this.request('/api/detect-keywords.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title, comment }),
    });
  },
  checkSources(url, keywords) {
    const params = new URLSearchParams();
    params.set('url', url);
    params.set('state', 'working');
    keywords.forEach((keyword) => params.append('keywords', keyword));
    return this.request(`/api/sources.php?${params.toString()}`);
  },
  createSource(payload) {
    return this.request('/api/sources.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  },
  listSources(authorId) {
    const params = new URLSearchParams();
    params.set('author_id', String(authorId));
    params.set('state', 'working');
    return this.request(`/api/sources.php?${params.toString()}`);
  },
  updateSourceState(sourceId, state) {
    return this.request('/api/sources.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ source_id: sourceId, state }),
    });
  },
};

const storageKey = 'editorial:lastAuthorId';
const appState = {
  users: [],
  selectedAuthorId: null,
};

function sanitizeUrl(raw) {
  const trimmed = raw.trim();
  if (trimmed === '') {
    return { value: '', hasQuery: false };
  }
  try {
    const parsed = new URL(trimmed);
    const hasQuery = parsed.searchParams && Array.from(parsed.searchParams.keys()).length > 0;
    parsed.search = '';
    parsed.hash = '';
    return { value: parsed.toString(), hasQuery };
  } catch (error) {
    return { value: '', hasQuery: false };
  }
}

function setStatus(target, message, tone = 'info') {
  target.textContent = message;
  target.dataset.tone = tone;
}

function createElement(tag, options = {}) {
  const el = document.createElement(tag);
  if (options.className) {
    el.className = options.className;
  }
  if (options.text) {
    el.textContent = options.text;
  }
  if (options.html) {
    el.innerHTML = options.html;
  }
  return el;
}

function renderTopbar(container) {
  const topbar = createElement('div', { className: 'topbar' });
  const title = createElement('div', { className: 'brand', text: '編集部システム' });
  const nav = createElement('nav', { className: 'nav' });
  const navLinks = [
    { hash: '#/users', label: 'ユーザー' },
    { hash: '#/new-source', label: '新規ソース' },
    { hash: '#/sources', label: '作業中ソース' },
  ];
  navLinks.forEach((link) => {
    const a = createElement('a', { text: link.label });
    a.href = link.hash;
    if (location.hash === link.hash) {
      a.classList.add('active');
    }
    nav.appendChild(a);
  });

  const userSelectWrap = createElement('div', { className: 'author-select' });
  const label = createElement('label', { text: '担当者' });
  const select = createElement('select');
  const defaultOption = createElement('option', { text: '選択してください' });
  defaultOption.value = '';
  select.appendChild(defaultOption);
  appState.users.forEach((user) => {
    const option = createElement('option', { text: user.name });
    option.value = String(user.id);
    if (appState.selectedAuthorId === user.id) {
      option.selected = true;
    }
    select.appendChild(option);
  });
  select.addEventListener('change', () => {
    const selected = select.value ? Number(select.value) : null;
    appState.selectedAuthorId = selected;
    if (selected) {
      localStorage.setItem(storageKey, String(selected));
    } else {
      localStorage.removeItem(storageKey);
    }
    renderApp();
  });
  userSelectWrap.appendChild(label);
  userSelectWrap.appendChild(select);

  topbar.appendChild(title);
  topbar.appendChild(nav);
  topbar.appendChild(userSelectWrap);
  container.appendChild(topbar);
}

function renderUsersPage(container) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: 'ユーザー管理' }));

  const list = createElement('div', { className: 'list' });
  appState.users.forEach((user) => {
    const item = createElement('div', { className: 'list-item' });
    item.appendChild(createElement('span', { text: user.name }));
    item.appendChild(createElement('span', { className: 'muted', text: `#${user.id}` }));
    list.appendChild(item);
  });
  if (appState.users.length === 0) {
    list.appendChild(createElement('div', { className: 'empty', text: 'まだユーザーがありません。' }));
  }

  const form = createElement('form', { className: 'inline-form' });
  const input = createElement('input');
  input.type = 'text';
  input.placeholder = '新規ユーザー名';
  const button = createElement('button', { text: '作成' });
  button.type = 'submit';
  const status = createElement('div', { className: 'status' });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = input.value.trim();
    if (!name) {
      setStatus(status, '名前を入力してください。', 'error');
      return;
    }
    setStatus(status, '作成中...', 'info');
    try {
      const created = await api.createUser(name);
      appState.users = [...appState.users, created];
      input.value = '';
      setStatus(status, '作成しました。', 'success');
      renderApp();
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  form.appendChild(input);
  form.appendChild(button);

  section.appendChild(list);
  section.appendChild(form);
  section.appendChild(status);
  container.appendChild(section);
}

function renderNewSourcePage(container) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: '新規ソース登録' }));

  const form = createElement('form', { className: 'stack-form' });
  const urlInput = createElement('input');
  urlInput.type = 'text';
  urlInput.placeholder = 'URL';
  const titleInput = createElement('input');
  titleInput.type = 'text';
  titleInput.placeholder = 'タイトル';
  const commentInput = document.createElement('textarea');
  commentInput.placeholder = 'コメント';

  const keywordsView = createElement('div', { className: 'keyword-list' });
  const keywordButton = createElement('button', { text: 'キーワード検出' });
  keywordButton.type = 'button';

  const checkButton = createElement('button', { text: '重複チェック' });
  checkButton.type = 'button';
  const submitButton = createElement('button', { text: '登録する' });
  submitButton.type = 'submit';

  const status = createElement('div', { className: 'status' });
  const matchSection = createElement('div', { className: 'matches' });
  const confirmSection = createElement('div', { className: 'confirm hidden' });

  let detectedKeywords = [];
  let latestMatches = null;
  let allowUrlOverride = false;
  let allowKeywordOverride = false;
  let sanitizedUrl = '';
  let hasQuery = false;

  function renderKeywords() {
    keywordsView.innerHTML = '';
    if (detectedKeywords.length === 0) {
      keywordsView.appendChild(createElement('div', { className: 'empty', text: 'キーワード未検出' }));
      return;
    }
    detectedKeywords.forEach((keyword) => {
      keywordsView.appendChild(createElement('span', { className: 'chip', text: keyword }));
    });
  }

  function renderMatches(matches) {
    matchSection.innerHTML = '';
    if (!matches) {
      return;
    }
    const urlMatches = matches.url_matches || [];
    const keywordMatches = matches.keyword_matches || [];
    if (urlMatches.length === 0 && keywordMatches.length === 0) {
      matchSection.appendChild(createElement('div', { className: 'success-box', text: '重複の可能性は見つかりませんでした。' }));
      confirmSection.classList.add('hidden');
      return;
    }

    if (urlMatches.length > 0) {
      const box = createElement('div', { className: 'match-box error' });
      box.appendChild(createElement('h3', { text: 'URL一致 (エラー)' }));
      urlMatches.forEach((match) => {
        const item = createElement('div', { className: 'match-item' });
        item.appendChild(createElement('div', { className: 'match-title', text: match.title || '(無題)' }));
        item.appendChild(createElement('div', { className: 'match-meta', text: `${match.author_name || '不明'} / ${match.url || ''}` }));
        if (match.comment) {
          item.appendChild(createElement('div', { className: 'match-comment', text: match.comment }));
        }
        box.appendChild(item);
      });
      matchSection.appendChild(box);
    }

    if (keywordMatches.length > 0) {
      const box = createElement('div', { className: 'match-box warning' });
      box.appendChild(createElement('h3', { text: 'キーワード一致 (警告)' }));
      keywordMatches.forEach((match) => {
        const item = createElement('div', { className: 'match-item' });
        item.appendChild(createElement('div', { className: 'match-title', text: match.title || '(無題)' }));
        const keywordLabel = match.keyword ? ` / ${match.keyword}` : '';
        item.appendChild(createElement('div', { className: 'match-meta', text: `${match.author_name || '不明'}${keywordLabel}` }));
        box.appendChild(item);
      });
      matchSection.appendChild(box);
    }

    confirmSection.classList.remove('hidden');
    const hasUrlMatches = urlMatches.length > 0;
    const hasKeywordMatches = keywordMatches.length > 0;
    confirmSection.innerHTML = '';
    confirmSection.appendChild(createElement('h3', { text: '確認' }));

    if (hasUrlMatches) {
      const urlRow = createElement('label', { className: 'confirm-row' });
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.disabled = !hasQuery;
      checkbox.addEventListener('change', () => {
        allowUrlOverride = checkbox.checked;
      });
      urlRow.appendChild(checkbox);
      urlRow.appendChild(createElement('span', { text: 'URL一致を無視して登録する' }));
      confirmSection.appendChild(urlRow);
      if (!hasQuery) {
        confirmSection.appendChild(createElement('div', { className: 'hint', text: 'クエリを含むURLの場合のみ無視可能です。' }));
      }
    }

    if (hasKeywordMatches) {
      const keywordRow = createElement('label', { className: 'confirm-row' });
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.addEventListener('change', () => {
        allowKeywordOverride = checkbox.checked;
      });
      keywordRow.appendChild(checkbox);
      keywordRow.appendChild(createElement('span', { text: 'キーワード一致を無視して登録する' }));
      confirmSection.appendChild(keywordRow);
    }
  }

  function resetMatches() {
    latestMatches = null;
    allowUrlOverride = false;
    allowKeywordOverride = false;
    matchSection.innerHTML = '';
    confirmSection.classList.add('hidden');
  }

  [urlInput, titleInput, commentInput].forEach((field) => {
    field.addEventListener('input', () => {
      resetMatches();
    });
  });

  keywordButton.addEventListener('click', async () => {
    const title = titleInput.value.trim();
    const comment = commentInput.value.trim();
    if (!title && !comment) {
      setStatus(status, 'タイトルかコメントを入力してください。', 'error');
      return;
    }
    setStatus(status, 'キーワード検出中...', 'info');
    try {
      const result = await api.detectKeywords(title, comment);
      detectedKeywords = Array.isArray(result.keywords) ? result.keywords : [];
      renderKeywords();
      resetMatches();
      setStatus(status, '検出完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  checkButton.addEventListener('click', async () => {
    const urlRaw = urlInput.value.trim();
    if (!urlRaw) {
      setStatus(status, 'URLを入力してください。', 'error');
      return;
    }
    const normalized = sanitizeUrl(urlRaw);
    sanitizedUrl = normalized.value;
    hasQuery = normalized.hasQuery;
    if (!sanitizedUrl) {
      setStatus(status, 'URLが不正です。', 'error');
      return;
    }
    setStatus(status, '重複チェック中...', 'info');
    allowUrlOverride = false;
    allowKeywordOverride = false;
    try {
      const result = await api.checkSources(sanitizedUrl, detectedKeywords);
      latestMatches = result;
      renderMatches(result);
      setStatus(status, 'チェック完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!appState.selectedAuthorId) {
      setStatus(status, '担当者を選択してください。', 'error');
      return;
    }
    if (!latestMatches) {
      setStatus(status, '重複チェックを実行してください。', 'error');
      return;
    }
    const urlRaw = urlInput.value.trim();
    const title = titleInput.value.trim();
    const comment = commentInput.value.trim();
    if (!urlRaw || !title) {
      setStatus(status, 'URLとタイトルを入力してください。', 'error');
      return;
    }
    const normalized = sanitizeUrl(urlRaw);
    sanitizedUrl = normalized.value;
    hasQuery = normalized.hasQuery;
    if (!sanitizedUrl) {
      setStatus(status, 'URLが不正です。', 'error');
      return;
    }
    if (latestMatches && (latestMatches.url_matches || []).length > 0 && !allowUrlOverride) {
      setStatus(status, 'URL一致の確認が必要です。', 'error');
      return;
    }
    if (latestMatches && (latestMatches.keyword_matches || []).length > 0 && !allowKeywordOverride) {
      setStatus(status, 'キーワード一致の確認が必要です。', 'error');
      return;
    }
    if ((latestMatches && (latestMatches.url_matches || []).length > 0) && !hasQuery) {
      setStatus(status, 'クエリを含むURLのみURL一致を無視できます。', 'error');
      return;
    }
    setStatus(status, '登録中...', 'info');
    try {
      const payload = {
        author_id: appState.selectedAuthorId,
        url: sanitizedUrl,
        title,
        comment,
        keywords: detectedKeywords,
      };
      await api.createSource(payload);
      urlInput.value = '';
      titleInput.value = '';
      commentInput.value = '';
      detectedKeywords = [];
      latestMatches = null;
      renderKeywords();
      matchSection.innerHTML = '';
      confirmSection.classList.add('hidden');
      setStatus(status, '登録しました。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  const authorNote = createElement('div', { className: 'hint', text: '担当者は画面上部で選択します。' });

  form.appendChild(authorNote);
  form.appendChild(urlInput);
  form.appendChild(titleInput);
  form.appendChild(commentInput);

  const actions = createElement('div', { className: 'actions' });
  actions.appendChild(keywordButton);
  actions.appendChild(checkButton);
  actions.appendChild(submitButton);

  form.appendChild(actions);
  form.appendChild(keywordsView);
  form.appendChild(matchSection);
  form.appendChild(confirmSection);
  form.appendChild(status);

  renderKeywords();
  section.appendChild(form);
  container.appendChild(section);
}

function renderSourcesPage(container) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: '作業中ソース' }));
  const status = createElement('div', { className: 'status' });
  section.appendChild(status);

  const list = createElement('div', { className: 'list' });
  section.appendChild(list);

  async function loadSources() {
    if (!appState.selectedAuthorId) {
      setStatus(status, '担当者を選択してください。', 'error');
      list.innerHTML = '';
      return;
    }
    setStatus(status, '読み込み中...', 'info');
    try {
      const result = await api.listSources(appState.selectedAuthorId);
      const sources = result.sources || [];
      list.innerHTML = '';
      if (sources.length === 0) {
        list.appendChild(createElement('div', { className: 'empty', text: '作業中ソースはありません。' }));
      }
      sources.forEach((source) => {
        const item = createElement('div', { className: 'list-item' });
        const meta = createElement('div', { className: 'source-meta' });
        meta.appendChild(createElement('div', { className: 'source-title', text: source.title || '(無題)' }));
        meta.appendChild(createElement('div', { className: 'muted', text: source.url }));
        if (source.comment) {
          meta.appendChild(createElement('div', { className: 'source-comment', text: source.comment }));
        }
        item.appendChild(meta);
        const actions = createElement('div', { className: 'actions' });
        const doneButton = createElement('button', { text: '完了' });
        const abortButton = createElement('button', { text: '中止' });
        doneButton.type = 'button';
        abortButton.type = 'button';
        doneButton.addEventListener('click', async () => {
          setStatus(status, '更新中...', 'info');
          try {
            await api.updateSourceState(source.id, 'done');
            await loadSources();
            setStatus(status, '更新しました。', 'success');
          } catch (error) {
            setStatus(status, error.message, 'error');
          }
        });
        abortButton.addEventListener('click', async () => {
          setStatus(status, '更新中...', 'info');
          try {
            await api.updateSourceState(source.id, 'aborted');
            await loadSources();
            setStatus(status, '更新しました。', 'success');
          } catch (error) {
            setStatus(status, error.message, 'error');
          }
        });
        actions.appendChild(doneButton);
        actions.appendChild(abortButton);
        item.appendChild(actions);
        list.appendChild(item);
      });
      setStatus(status, '読み込み完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  loadSources();
  container.appendChild(section);
}

function renderRoute(container) {
  const route = location.hash || '#/new-source';
  if (route === '#/users') {
    renderUsersPage(container);
  } else if (route === '#/sources') {
    renderSourcesPage(container);
  } else {
    renderNewSourcePage(container);
  }
}

function renderApp() {
  const root = document.getElementById('main');
  root.innerHTML = '';
  renderTopbar(root);
  const content = createElement('div', { className: 'content' });
  renderRoute(content);
  root.appendChild(content);
}

async function init() {
  const cached = localStorage.getItem(storageKey);
  const hasCached = cached !== null;
  if (hasCached && cached !== '' && !Number.isNaN(Number(cached))) {
    appState.selectedAuthorId = Number(cached);
  }
  try {
    const result = await api.getUsers();
    appState.users = result.users || [];
    if (!hasCached && !appState.selectedAuthorId && appState.users.length > 0) {
      appState.selectedAuthorId = appState.users[0].id;
    }
  } catch (error) {
    appState.users = [];
  }
  renderApp();
}

window.addEventListener('hashchange', renderApp);
window.addEventListener('load', init);
