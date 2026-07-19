export class ApiError extends Error {
  constructor(code, message, status) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

export class ApiClient {
  constructor(baseUrl) {
    this.endpoint = new URL('api/index.php', baseUrl).href;
    this.csrfToken = '';
    this.accessCode = '';
  }

  setAccessCode(accessCode) {
    this.accessCode = accessCode || '';
  }

  async request(action, { method = 'GET', params = {}, body, signal } = {}) {
    const url = new URL(this.endpoint);
    url.searchParams.set('action', action);
    for (const [name, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        url.searchParams.set(name, String(value));
      }
    }

    const headers = { Accept: 'application/json' };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    if (this.csrfToken && method !== 'GET') {
      headers['X-ReviewLayer-CSRF'] = this.csrfToken;
    }
    if (this.accessCode) {
      headers['X-ReviewLayer-Access'] = this.accessCode;
    }

    let response;
    try {
      response = await fetch(url, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
        cache: 'no-store',
        signal
      });
    } catch (error) {
      if (error.name === 'AbortError') {
        throw error;
      }
      throw new ApiError('NETWORK_ERROR', error.message, 0);
    }

    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new ApiError('INVALID_RESPONSE', error.message, response.status);
    }

    if (!response.ok || payload.success !== true) {
      const apiError = payload?.error || {};
      throw new ApiError(apiError.code || 'REQUEST_FAILED', apiError.message || 'Request failed.', response.status);
    }

    return payload.data;
  }

  async bootstrap(signal) {
    const data = await this.request('bootstrap', { signal });
    this.csrfToken = data.csrf_token;
    return data;
  }

  listPins(context, signal) {
    return this.request('list-pins', { params: context, signal });
  }

  listProjectPins(projectKey, signal) {
    return this.request('list-project-pins', { params: { project_key: projectKey }, signal });
  }

  getPin(id, projectKey, signal) {
    return this.request('get-pin', { params: { id, project_key: projectKey }, signal });
  }

  createPin(body, signal) {
    return this.request('create-pin', { method: 'POST', body, signal });
  }

  addMessage(id, body, signal) {
    return this.request('add-message', { method: 'POST', params: { id }, body, signal });
  }

  updateStatus(id, body, signal) {
    return this.request('update-status', { method: 'PATCH', params: { id }, body, signal });
  }

  deletePin(id, body, signal) {
    return this.request('delete-pin', { method: 'DELETE', params: { id }, body, signal });
  }

  deleteMessage(id, body, signal) {
    return this.request('delete-message', { method: 'DELETE', params: { id }, body, signal });
  }

  createBackup(body, signal) {
    return this.request('create-backup', { method: 'POST', body, signal });
  }

  clearData(body, signal) {
    return this.request('admin-clear', { method: 'POST', body, signal });
  }
}
