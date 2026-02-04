const WORKING_SOURCES_LIMIT = 20;

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
  listKeywords() {
    return this.request('/api/keywords.php');
  },
  getUserCounts(userId) {
    const params = new URLSearchParams();
    params.set('user_id', String(userId));
    return this.request(`/api/user-counts.php?${params.toString()}`);
  },
  updateUser(userId, name) {
    return this.request('/api/users.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: userId, name }),
    });
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
    keywords.forEach((keyword) => params.append('keywords[]', keyword));
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
  getSource(sourceId) {
    const params = new URLSearchParams();
    params.set('source_id', String(sourceId));
    return this.request(`/api/sources.php?${params.toString()}`);
  },
  searchKeywords(query) {
    const params = new URLSearchParams();
    params.set('query', query);
    return this.request(`/api/search-keywords.php?${params.toString()}`);
  },
  searchSources(keywords, state) {
    const params = new URLSearchParams();
    keywords.forEach((keyword) => params.append('keywords[]', keyword));
    if (state) {
      params.set('state', state);
    }
    return this.request(`/api/search-sources.php?${params.toString()}`);
  },
  updateSourceState(sourceId, state) {
    return this.request('/api/sources.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ source_id: sourceId, state }),
    });
  },
  updateSource(sourceId, payload) {
    return this.request('/api/sources.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ source_id: sourceId, ...payload }),
    });
  },
};

const storageKey = 'editorial:lastAuthorId';
const appState = {
  users: [],
  selectedAuthorId: null,
};

function normalizeUsers(users) {
  return (Array.isArray(users) ? users : []).map((user) => ({
    ...user,
    id: Number(user.id),
  }));
}

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

function renderKeywordChips(keywords) {
  const wrapper = createElement('div', { className: 'keyword-list' });
  const unique = [...new Set(keywords)].filter((keyword) => keyword !== '');
  unique.forEach((keyword) => {
    const link = createElement('a', { className: 'chip chip-link', text: keyword });
    link.href = `#/keywords/${encodeURIComponent(keyword)}`;
    wrapper.appendChild(link);
  });
  return wrapper;
}

function formatTimestamp(seconds) {
  if (seconds === null || seconds === undefined || seconds === '') {
    return '';
  }
  const value = Number(seconds);
  if (Number.isNaN(value) || value <= 0) {
    return '';
  }
  const date = new Date(value * 1000);
  const pad = (num) => String(num).padStart(2, '0');
  const year = date.getFullYear();
  const month = pad(date.getMonth() + 1);
  const day = pad(date.getDate());
  const hours = pad(date.getHours());
  const minutes = pad(date.getMinutes());
  const secondsPart = pad(date.getSeconds());
  const offsetMinutes = -date.getTimezoneOffset();
  const sign = offsetMinutes >= 0 ? '+' : '-';
  const absMinutes = Math.abs(offsetMinutes);
  const offsetHours = pad(Math.floor(absMinutes / 60));
  const offsetMins = pad(absMinutes % 60);
  return `${year}-${month}-${day} ${hours}:${minutes}:${secondsPart} (${sign}${offsetHours}${offsetMins})`;
}

function renderTimestamp(value) {
  const formatted = formatTimestamp(value);
  if (!formatted) {
    return null;
  }
  return createElement('div', { className: 'muted', text: `更新: ${formatted}` });
}

function renderTopbar(container) {
  const topbar = createElement('div', { className: 'topbar' });
  const title = createElement('div', { className: 'brand', text: '編集部システム' });
  const nav = createElement('nav', { className: 'nav' });
  const navLinks = [
    { hash: '#/users', label: 'ユーザー' },
    { hash: '#/keywords', label: 'キーワード' },
  ];
  navLinks.forEach((link) => {
    const a = createElement('a', { text: link.label });
    a.href = link.hash;
    if (location.hash.startsWith(link.hash)) {
      a.classList.add('active');
    }
    nav.appendChild(a);
  });

  const sourcesActive = location.hash.startsWith('#/sources') ? '#/sources' : '#/new-source';
  const sourcesDropdown = createElement('div', { className: 'nav-dropdown' });
  const sourcesToggle = createElement('button', { className: 'nav-dropdown-toggle', text: 'ソース' });
  sourcesToggle.type = 'button';
  const sourcesMenu = createElement('ul', { className: 'nav-dropdown-menu' });
  const sourcesOptions = [
    { hash: '#/new-source', label: '新規ソース' },
    { hash: '#/sources', label: '作業中ソース' },
  ];
  sourcesOptions.forEach((option) => {
    const item = createElement('li');
    const link = createElement('a', { text: option.label });
    link.href = option.hash;
    if (sourcesActive === option.hash) {
      link.classList.add('active');
    }
    item.appendChild(link);
    sourcesMenu.appendChild(item);
  });
  sourcesToggle.addEventListener('click', () => {
    sourcesDropdown.classList.toggle('open');
  });
  document.addEventListener('click', (event) => {
    if (!sourcesDropdown.contains(event.target)) {
      sourcesDropdown.classList.remove('open');
    }
  });
  sourcesDropdown.appendChild(sourcesToggle);
  sourcesDropdown.appendChild(sourcesMenu);
  nav.appendChild(sourcesDropdown);

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

  const searchForm = createElement('form', { className: 'search-form' });
  const searchInput = createElement('input');
  searchInput.type = 'search';
  searchInput.placeholder = 'キーワード検索';
  const searchButton = createElement('button', { text: '検索' });
  searchButton.type = 'submit';
  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = searchInput.value.trim();
    if (!query) {
      return;
    }
    searchInput.value = '';
    location.hash = `#/search/${encodeURIComponent(query)}`;
  });
  searchForm.appendChild(searchInput);
  searchForm.appendChild(searchButton);
  topbar.appendChild(searchForm);

  topbar.appendChild(userSelectWrap);
  container.appendChild(topbar);
}

function renderUsersPage(container) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: 'ユーザー管理' }));

  const intro = createElement('div', { className: 'hint', text: '最初にユーザーを作成してください。作成後は上部の担当者選択から利用できます。' });

  const list = createElement('div', { className: 'list' });
  appState.users.forEach((user) => {
    const item = createElement('a', { className: 'list-item link-item' });
    item.href = `#/users/${encodeURIComponent(String(user.id))}`;
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
      appState.users = [...appState.users, ...normalizeUsers([created])];
      if (!appState.selectedAuthorId) {
        appState.selectedAuthorId = Number(created.id);
        localStorage.setItem(storageKey, String(created.id));
      }
      input.value = '';
      setStatus(status, '作成しました。', 'success');
      renderApp();
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  form.appendChild(input);
  form.appendChild(button);

  section.appendChild(form);
  section.appendChild(status);
  section.appendChild(intro);
  section.appendChild(list);
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
  const titlePreview = createElement('div', { className: 'hint' });
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
  let detectedTitleJa = '';
  let keywordsDetected = false;
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
    keywordsView.appendChild(renderKeywordChips(detectedKeywords));
  }

  function renderTitlePreview() {
    if (!detectedTitleJa) {
      titlePreview.textContent = '';
      return;
    }
    titlePreview.textContent = `日本語タイトル案: ${detectedTitleJa}`;
  }

  function updateButtonStates() {
    checkButton.disabled = !keywordsDetected;
    submitButton.disabled = !latestMatches || !appState.selectedAuthorId;
  }

  function renderMatches(matches) {
    matchSection.innerHTML = '';
    if (!matches) {
      updateButtonStates();
      return;
    }
    const urlMatches = matches.url_matches || [];
    const keywordMatches = matches.keyword_matches || [];
    if (urlMatches.length === 0 && keywordMatches.length === 0) {
      matchSection.appendChild(createElement('div', { className: 'success-box', text: '重複の可能性は見つかりませんでした。' }));
      confirmSection.classList.add('hidden');
      updateButtonStates();
      return;
    }

    if (urlMatches.length > 0) {
      const box = createElement('div', { className: 'match-box error' });
      box.appendChild(createElement('h3', { text: 'URL一致 (エラー)' }));
      urlMatches.forEach((match) => {
        const item = createElement('div', { className: 'match-item' });
        item.appendChild(createElement('div', { className: 'match-title', text: match.title || '(無題)' }));
        item.appendChild(createElement('div', { className: 'match-meta', text: `${match.author_name || '不明'} / ${match.url || ''}` }));
        const timestamp = renderTimestamp(match.updated_date);
        if (timestamp) {
          item.appendChild(timestamp);
        }
        if (match.keywords) {
          const keywords = String(match.keywords)
            .split(',')
            .map((keyword) => keyword.trim())
            .filter((keyword) => keyword !== '');
          if (keywords.length > 0) {
            item.appendChild(renderKeywordChips(keywords));
          }
        }
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
      const grouped = new Map();
      keywordMatches.forEach((match) => {
        const idValue = match.id !== undefined && match.id !== null ? String(match.id) : '';
        const key = idValue !== '' ? idValue : `${match.url || ''}||${match.title || ''}||${match.author_id || ''}`;
        if (!grouped.has(key)) {
          grouped.set(key, { ...match, keywords: [] });
        }
        if (match.keywords) {
          const split = String(match.keywords)
            .split(',')
            .map((keyword) => keyword.trim())
            .filter((keyword) => keyword !== '');
          grouped.get(key).keywords.push(...split);
        }
        if (match.keyword) {
          grouped.get(key).keywords.push(match.keyword);
        }
      });
      grouped.forEach((match) => {
        const item = createElement('div', { className: 'match-item' });
        item.appendChild(createElement('div', { className: 'match-title', text: match.title || '(無題)' }));
        item.appendChild(createElement('div', { className: 'match-meta', text: `${match.author_name || '不明'}` }));
        const timestamp = renderTimestamp(match.updated_date);
        if (timestamp) {
          item.appendChild(timestamp);
        }
        const uniqueKeywords = [...new Set(match.keywords)].filter((keyword) => keyword !== '');
        if (uniqueKeywords.length > 0) {
          item.appendChild(renderKeywordChips(uniqueKeywords));
        }
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

    updateButtonStates();
  }

  function resetMatches() {
    latestMatches = null;
    allowUrlOverride = false;
    allowKeywordOverride = false;
    matchSection.innerHTML = '';
    confirmSection.classList.add('hidden');
    updateButtonStates();
  }

  [urlInput, titleInput, commentInput].forEach((field) => {
    field.addEventListener('input', () => {
      resetMatches();
      if (field === titleInput || field === commentInput) {
        keywordsDetected = false;
        detectedKeywords = [];
        detectedTitleJa = '';
        renderKeywords();
        renderTitlePreview();
      }
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
      detectedTitleJa = typeof result.title_ja === 'string' ? result.title_ja.trim() : '';
      if (detectedTitleJa) {
        titleInput.value = detectedTitleJa;
      }
      keywordsDetected = true;
      renderKeywords();
      renderTitlePreview();
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
    try {
      const counts = await api.getUserCounts(appState.selectedAuthorId);
      const workingCount = counts.counts ? counts.counts.working : 0;
      if (workingCount >= WORKING_SOURCES_LIMIT) {
        setStatus(status, `作業中の上限（${WORKING_SOURCES_LIMIT}件）に達しています。`, 'error');
        return;
      }
    } catch (error) {
      setStatus(status, error.message, 'error');
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
      detectedTitleJa = '';
      keywordsDetected = false;
      latestMatches = null;
      renderKeywords();
      renderTitlePreview();
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
  form.appendChild(titlePreview);
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
  renderTitlePreview();
  updateButtonStates();
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
        const titleLink = createElement('a', {
          className: 'source-title source-link',
          text: source.title || '(無題)',
        });
        titleLink.href = `#/sources/${encodeURIComponent(String(source.id))}`;
        meta.appendChild(titleLink);
        meta.appendChild(createElement('div', { className: 'muted', text: source.url }));
        const timestamp = renderTimestamp(source.updated_date);
        if (timestamp) {
          meta.appendChild(timestamp);
        }
        if (source.comment) {
          meta.appendChild(createElement('div', { className: 'source-comment', text: source.comment }));
        }
        if (source.keywords) {
          const keywords = String(source.keywords)
            .split(',')
            .map((keyword) => keyword.trim())
            .filter((keyword) => keyword !== '');
          if (keywords.length > 0) {
            meta.appendChild(renderKeywordChips(keywords));
          }
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

function renderSourceDetailPage(container, sourceId) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: `ソース #${sourceId}` }));
  const status = createElement('div', { className: 'status' });
  const meta = createElement('div', { className: 'source-detail-meta' });
  const keywordsView = createElement('div', { className: 'keyword-list' });

  const form = createElement('form', { className: 'stack-form' });
  const titleInput = createElement('input');
  titleInput.type = 'text';
  titleInput.placeholder = 'タイトル';
  const commentInput = document.createElement('textarea');
  commentInput.placeholder = 'コメント';
  const contentInput = document.createElement('textarea');
  contentInput.placeholder = '本文 (Markdown)';
  contentInput.className = 'textarea-large';
  const saveButton = createElement('button', { text: '更新する' });
  saveButton.type = 'submit';

  form.appendChild(titleInput);
  form.appendChild(commentInput);
  form.appendChild(contentInput);
  form.appendChild(saveButton);

  section.appendChild(status);
  section.appendChild(meta);
  section.appendChild(keywordsView);
  section.appendChild(form);
  container.appendChild(section);

  const sourceNumber = Number(sourceId);
  if (!Number.isFinite(sourceNumber) || sourceNumber <= 0) {
    setStatus(status, 'Invalid source_id.', 'error');
    return;
  }

  function renderMeta(source) {
    meta.innerHTML = '';
    if (source.url) {
      const link = createElement('a', { className: 'source-url', text: source.url });
      link.href = source.url;
      link.target = '_blank';
      link.rel = 'noreferrer';
      meta.appendChild(link);
    }
    meta.appendChild(createElement('div', { className: 'muted', text: `担当者: ${source.author_name || '不明'}` }));
    const timestamp = renderTimestamp(source.updated_date);
    if (timestamp) {
      meta.appendChild(timestamp);
    }
  }

  function renderKeywords(source) {
    keywordsView.innerHTML = '';
    if (!source.keywords) {
      keywordsView.appendChild(createElement('div', { className: 'empty', text: 'キーワードなし' }));
      return;
    }
    const keywords = String(source.keywords)
      .split(',')
      .map((keyword) => keyword.trim())
      .filter((keyword) => keyword !== '');
    if (keywords.length === 0) {
      keywordsView.appendChild(createElement('div', { className: 'empty', text: 'キーワードなし' }));
      return;
    }
    keywordsView.appendChild(renderKeywordChips(keywords));
  }

  async function loadSource() {
    setStatus(status, '読み込み中...', 'info');
    try {
      const result = await api.getSource(sourceNumber);
      const source = result.source;
      if (!source) {
        setStatus(status, '見つかりませんでした。', 'error');
        return;
      }
      titleInput.value = source.title || '';
      commentInput.value = source.comment || '';
      contentInput.value = source.content_md || '';
      renderMeta(source);
      renderKeywords(source);
      setStatus(status, '読み込み完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setStatus(status, '更新中...', 'info');
    saveButton.disabled = true;
    try {
      await api.updateSource(sourceNumber, {
        title: titleInput.value.trim(),
        comment: commentInput.value,
        content_md: contentInput.value,
      });
      await loadSource();
      setStatus(status, '更新しました。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    } finally {
      saveButton.disabled = false;
    }
  });

  loadSource();
}

function renderKeywordListPage(container) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: 'キーワード一覧' }));
  const status = createElement('div', { className: 'status' });
  section.appendChild(status);
  const list = createElement('div', { className: 'list' });
  section.appendChild(list);

  async function loadKeywords() {
    setStatus(status, '読み込み中...', 'info');
    try {
      const result = await api.listKeywords();
      const keywords = result.keywords || [];
      list.innerHTML = '';
      if (keywords.length === 0) {
        list.appendChild(createElement('div', { className: 'empty', text: 'キーワードがありません。' }));
      }
      keywords.forEach((item) => {
        const keyword = item.keyword || '';
        const count = item.count ?? 0;
        const entry = createElement('a', { className: 'list-item link-item' });
        entry.href = `#/keywords/${encodeURIComponent(keyword)}`;
        entry.appendChild(createElement('span', { text: keyword }));
        entry.appendChild(createElement('span', { className: 'muted', text: `${count}件` }));
        list.appendChild(entry);
      });
      setStatus(status, '読み込み完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  loadKeywords();
  container.appendChild(section);
}

function renderKeywordDetailPage(container, keyword) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: `キーワード: ${keyword}` }));
  const status = createElement('div', { className: 'status' });
  section.appendChild(status);

  const workingBox = createElement('div', { className: 'match-box' });
  workingBox.appendChild(createElement('h3', { text: '作業中' }));
  const workingList = createElement('div', { className: 'list' });
  workingBox.appendChild(workingList);

  const doneBox = createElement('div', { className: 'match-box' });
  doneBox.appendChild(createElement('h3', { text: '完了' }));
  const doneList = createElement('div', { className: 'list' });
  doneBox.appendChild(doneList);

  section.appendChild(workingBox);
  section.appendChild(doneBox);

  async function loadState(state, target) {
    const result = await api.searchSources([keyword], state);
      const sources = result.sources || [];
      target.innerHTML = '';
      if (sources.length === 0) {
        target.appendChild(createElement('div', { className: 'empty', text: '該当なし' }));
        return;
      }
      sources.forEach((source) => {
        const item = createElement('div', { className: 'list-item' });
        const meta = createElement('div', { className: 'source-meta' });
        meta.appendChild(createElement('div', { className: 'source-title', text: source.title || '(無題)' }));
        meta.appendChild(createElement('div', { className: 'muted', text: source.url }));
        meta.appendChild(createElement('div', { className: 'match-meta', text: source.author_name || '不明' }));
        const timestamp = renderTimestamp(source.updated_date);
        if (timestamp) {
          meta.appendChild(timestamp);
        }
        if (source.comment) {
          meta.appendChild(createElement('div', { className: 'source-comment', text: source.comment }));
        }
      if (source.keywords) {
        const keywords = String(source.keywords)
          .split(',')
          .map((value) => value.trim())
          .filter((value) => value !== '');
        if (keywords.length > 0) {
          meta.appendChild(renderKeywordChips(keywords));
        }
      }
      item.appendChild(meta);
      target.appendChild(item);
    });
  }

  async function loadAll() {
    setStatus(status, '読み込み中...', 'info');
    try {
      await loadState('working', workingList);
      await loadState('done', doneList);
      setStatus(status, '読み込み完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  loadAll();
  container.appendChild(section);
}

function renderSearchPage(container, query) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: `検索: ${query}` }));
  const status = createElement('div', { className: 'status' });
  const keywordBox = createElement('div', { className: 'keyword-list' });
  const resultsBox = createElement('div', { className: 'list' });
  section.appendChild(status);
  section.appendChild(keywordBox);
  section.appendChild(resultsBox);

  async function loadSearch() {
    setStatus(status, '解析中...', 'info');
    try {
      const keywordResult = await api.searchKeywords(query);
      const keywords = Array.isArray(keywordResult.keywords) ? keywordResult.keywords : [];
      keywordBox.innerHTML = '';
      if (keywords.length === 0) {
        keywordBox.appendChild(createElement('div', { className: 'empty', text: 'キーワードが見つかりませんでした。' }));
        resultsBox.innerHTML = '';
        setStatus(status, '完了。', 'success');
        return;
      }
      keywordBox.appendChild(renderKeywordChips(keywords));

      const [workingResult, doneResult] = await Promise.all([
        api.searchSources(keywords, 'working'),
        api.searchSources(keywords, 'done'),
      ]);
      const combined = [
        ...((workingResult.sources || []).map((source) => ({ ...source, state_label: 'working' }))),
        ...((doneResult.sources || []).map((source) => ({ ...source, state_label: 'done' }))),
      ];
      resultsBox.innerHTML = '';
      if (combined.length === 0) {
        resultsBox.appendChild(createElement('div', { className: 'empty', text: '該当ソースがありません。' }));
        setStatus(status, '完了。', 'success');
        return;
      }
      combined.forEach((source) => {
        const item = createElement('div', { className: 'list-item' });
        const meta = createElement('div', { className: 'source-meta' });
        meta.appendChild(createElement('div', { className: 'source-title', text: source.title || '(無題)' }));
        meta.appendChild(createElement('div', { className: 'muted', text: source.url }));
        meta.appendChild(createElement('div', { className: 'match-meta', text: source.author_name || '不明' }));
        const timestamp = renderTimestamp(source.updated_date);
        if (timestamp) {
          meta.appendChild(timestamp);
        }
        if (source.comment) {
          meta.appendChild(createElement('div', { className: 'source-comment', text: source.comment }));
        }
        if (source.keywords) {
          const keywords = String(source.keywords)
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
          if (keywords.length > 0) {
            meta.appendChild(renderKeywordChips(keywords));
          }
        }
        item.appendChild(meta);
        const badge = createElement('span', {
          className: `state-badge state-${source.state_label}`,
          text: source.state_label === 'done' ? '完了' : '作業中',
        });
        item.appendChild(badge);
        resultsBox.appendChild(item);
      });
      setStatus(status, '完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  loadSearch();
  container.appendChild(section);
}

function renderUserDetailPage(container, userId) {
  const section = createElement('section', { className: 'panel' });
  section.appendChild(createElement('h2', { text: `ユーザー詳細 #${userId}` }));
  const status = createElement('div', { className: 'status' });
  const countsRow = createElement('div', { className: 'list' });
  const form = createElement('form', { className: 'inline-form' });
  const input = createElement('input');
  input.type = 'text';
  input.placeholder = 'ユーザー名';
  const saveButton = createElement('button', { text: '更新' });
  saveButton.type = 'submit';
  form.appendChild(input);
  form.appendChild(saveButton);

  section.appendChild(status);
  section.appendChild(form);
  section.appendChild(countsRow);

  const user = appState.users.find((entry) => String(entry.id) === String(userId));
  if (user) {
    input.value = user.name;
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = input.value.trim();
    if (!name) {
      setStatus(status, '名前を入力してください。', 'error');
      return;
    }
    setStatus(status, '更新中...', 'info');
    try {
      const updated = await api.updateUser(Number(userId), name);
      appState.users = appState.users.map((entry) => (entry.id === updated.id ? updated : entry));
      setStatus(status, '更新しました。', 'success');
      renderApp();
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });

  async function loadCounts() {
    setStatus(status, '読み込み中...', 'info');
    try {
      const result = await api.getUserCounts(Number(userId));
      const counts = result.counts || { working: 0, done: 0, aborted: 0 };
      countsRow.innerHTML = '';
      const entries = [
        { label: '作業中', value: counts.working, className: 'state-working' },
        { label: '完了', value: counts.done, className: 'state-done' },
        { label: '中止', value: counts.aborted, className: 'state-aborted' },
      ];
      entries.forEach((entry) => {
        const item = createElement('div', { className: 'list-item' });
        item.appendChild(createElement('span', { text: entry.label }));
        const badge = createElement('span', {
          className: `state-badge ${entry.className}`,
          text: `${entry.value}件`,
        });
        item.appendChild(badge);
        countsRow.appendChild(item);
      });
      setStatus(status, '読み込み完了。', 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  loadCounts();
  container.appendChild(section);
}

function renderRoute(container) {
  const route = location.hash || '#/new-source';
  if (route === '#/users') {
    renderUsersPage(container);
  } else if (route === '#/sources') {
    renderSourcesPage(container);
  } else if (route.startsWith('#/sources/')) {
    const sourceId = decodeURIComponent(route.replace('#/sources/', ''));
    renderSourceDetailPage(container, sourceId);
  } else if (route === '#/keywords') {
    renderKeywordListPage(container);
  } else if (route.startsWith('#/keywords/')) {
    const keyword = decodeURIComponent(route.replace('#/keywords/', ''));
    renderKeywordDetailPage(container, keyword);
  } else if (route.startsWith('#/users/')) {
    const userId = decodeURIComponent(route.replace('#/users/', ''));
    renderUserDetailPage(container, userId);
  } else if (route.startsWith('#/search/')) {
    const query = decodeURIComponent(route.replace('#/search/', ''));
    renderSearchPage(container, query);
  } else {
    renderNewSourcePage(container);
  }
}

function renderBreadcrumbs(container) {
  const route = location.hash || '#/new-source';
  const crumbs = [];
  if (route.startsWith('#/users/')) {
    const id = decodeURIComponent(route.replace('#/users/', ''));
    crumbs.push({ label: 'ユーザー', hash: '#/users' });
    crumbs.push({ label: `ユーザー #${id}` });
  } else if (route.startsWith('#/users')) {
    crumbs.push({ label: 'ユーザー' });
  } else if (route.startsWith('#/keywords/')) {
    const keyword = decodeURIComponent(route.replace('#/keywords/', ''));
    crumbs.push({ label: 'キーワード', hash: '#/keywords' });
    crumbs.push({ label: keyword });
  } else if (route.startsWith('#/keywords')) {
    crumbs.push({ label: 'キーワード' });
  } else if (route.startsWith('#/search/')) {
    const query = decodeURIComponent(route.replace('#/search/', ''));
    crumbs.push({ label: '検索' });
    crumbs.push({ label: query });
  } else if (route.startsWith('#/sources/')) {
    const id = decodeURIComponent(route.replace('#/sources/', ''));
    crumbs.push({ label: 'ソース', hash: '#/sources' });
    crumbs.push({ label: `#${id}` });
  } else if (route.startsWith('#/sources')) {
    crumbs.push({ label: 'ソース' });
    crumbs.push({ label: '作業中' });
  } else {
    crumbs.push({ label: 'ソース' });
    crumbs.push({ label: '新規登録' });
  }

  const wrapper = createElement('div', { className: 'breadcrumbs' });
  crumbs.forEach((crumb, index) => {
    if (index > 0) {
      wrapper.appendChild(createElement('span', { className: 'crumb-sep', text: '/' }));
    }
    if (crumb.hash) {
      const link = createElement('a', { text: crumb.label });
      link.href = crumb.hash;
      wrapper.appendChild(link);
    } else {
      wrapper.appendChild(createElement('span', { text: crumb.label }));
    }
  });
  container.appendChild(wrapper);
}

function renderApp() {
  const root = document.getElementById('main');
  root.innerHTML = '';
  renderTopbar(root);
  const content = createElement('div', { className: 'content' });
  renderBreadcrumbs(content);
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
    appState.users = normalizeUsers(result.users);
  } catch (error) {
    appState.users = [];
  }
  renderApp();

  if (!hasCached && !appState.selectedAuthorId) {
    location.hash = '#/users';
  }
}

window.addEventListener('hashchange', renderApp);
window.addEventListener('load', init);
