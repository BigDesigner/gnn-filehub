/* GNN Filehub - Zero Dependency Native Public Drag & Drop, Manager, Password & Register JS */

/*
 * Live theming: measures the page's actual rendered background color and toggles
 * .filehub-theme-dark on every .filehub-container so our cards always match the theme's
 * CURRENT state — whatever mechanism the theme uses to switch light/dark (OS preference,
 * a manual toggle button, a class or data-attribute on <html>/<body>) — instead of guessing
 * from a single signal like prefers-color-scheme, which can disagree with what the page is
 * actually showing.
 */
(function () {
  function relativeLuminance(rgbString) {
    var m = rgbString && rgbString.match(/[\d.]+/g);
    if (!m || m.length < 3) return 1; // assume light if we can't tell
    var r = Number(m[0]), g = Number(m[1]), b = Number(m[2]);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  }

  function getEffectiveBackgroundColor(startEl) {
    var el = startEl;
    while (el && el !== document.documentElement) {
      var bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        return bg;
      }
      el = el.parentElement;
    }
    var htmlBg = getComputedStyle(document.documentElement).backgroundColor;
    if (htmlBg && htmlBg !== 'rgba(0, 0, 0, 0)') return htmlBg;
    return getComputedStyle(document.body).backgroundColor;
  }

  function applyFilehubTheming() {
    var containers = document.querySelectorAll('.filehub-container');
    containers.forEach(function (container) {
      var bg = getEffectiveBackgroundColor(container.parentElement || document.body);
      var isDark = relativeLuminance(bg) < 0.5;
      container.classList.toggle('filehub-theme-dark', isDark);
    });
  }

  function init() {
    applyFilehubTheming();

    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function () {
        applyFilehubTheming();
      });
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
      observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
    }

    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) {
        mq.addEventListener('change', applyFilehubTheming);
      } else if (mq.addListener) {
        mq.addListener(applyFilehubTheming);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

document.addEventListener('DOMContentLoaded', function () {
  const dropZone = document.getElementById('filehub-dropzone');
  const fileInput = document.getElementById('filehub-file-input');
  const progressBar = document.getElementById('filehub-progress-bar');
  const progressFill = document.getElementById('filehub-progress-fill');
  const statusText = document.getElementById('filehub-status-text');
  const passwordForm = document.getElementById('filehub-password-form');
  const passwordStatus = document.getElementById('filehub-password-status');
  const registerForm = document.getElementById('filehub-register-form');
  const registerStatus = document.getElementById('filehub-register-status');

  // Cache of the last fetched (unfiltered) rows per file-list container, for live search.
  const fileListCache = new WeakMap();

  // Drag & Drop File Upload
  if (dropZone && fileInput) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
      e.preventDefault();
      e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.add('filehub-highlight');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.remove('filehub-highlight');
      });
    });

    dropZone.addEventListener('drop', handleDrop, false);
    fileInput.addEventListener('change', function () {
      if (this.files.length > 0) {
        uploadFile(this.files[0]);
      }
    });

    function handleDrop(e) {
      const dt = e.dataTransfer;
      const files = dt.files;
      if (files.length > 0) {
        uploadFile(files[0]);
      }
    }

    const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB chunks

    function uploadFile(file) {
      if (file.size > CHUNK_SIZE) {
        uploadFileChunked(file);
      } else {
        uploadFileSingle(file);
      }
    }

    function uploadFileSingle(file) {
      const formData = new FormData();
      formData.append('file', file);

      const xhr = new XMLHttpRequest();
      const startTime = new Date().getTime();

      if (progressBar) progressBar.style.display = 'block';
      if (statusText) statusText.textContent = 'Yükleme başlatılıyor...';

      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) {
          const percent = Math.round((e.loaded / e.total) * 100);
          const elapsedTime = (new Date().getTime() - startTime) / 1000;
          const speedBytes = elapsedTime > 0 ? e.loaded / elapsedTime : 0;
          const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);

          if (progressFill) progressFill.style.width = percent + '%';
          if (statusText) statusText.textContent = `Yükleniyor: %${percent} (${speedMB} MB/s)`;
        }
      });

      xhr.onreadystatechange = function () {
        if (xhr.readyState === XMLHttpRequest.DONE) {
          if (xhr.status === 200) {
            if (statusText) statusText.textContent = 'Yükleme başarıyla tamamlandı!';
            if (progressFill) progressFill.style.width = '100%';
            setTimeout(() => {
              if (progressBar) progressBar.style.display = 'none';
              refreshAllFileLists();
            }, 1200);
          } else {
            try {
              const resp = JSON.parse(xhr.responseText);
              if (statusText) statusText.textContent = 'Hata: ' + (resp.error || 'Yükleme başarısız.');
            } catch (err) {
              if (statusText) statusText.textContent = 'Sunucu yükleme hatası.';
            }
          }
        }
      };

      xhr.open('POST', filehub_vars.rest_url + 'filehub/v1/upload', true);
      xhr.setRequestHeader('X-WP-Nonce', filehub_vars.nonce);
      xhr.send(formData);
    }

    function uploadFileChunked(file) {
      const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
      const fileId = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
      let currentChunk = 0;
      const startTime = new Date().getTime();
      let totalUploadedBytes = 0;

      if (progressBar) progressBar.style.display = 'block';
      if (statusText) statusText.textContent = `Parçalı yükleme başlatılıyor (Toplam ${totalChunks} parça)...`;

      function sendNextChunk() {
        if (currentChunk >= totalChunks) return;

        const start = currentChunk * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const chunkBlob = file.slice(start, end);

        const formData = new FormData();
        formData.append('chunk', chunkBlob, file.name);
        formData.append('file_id', fileId);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('filename', file.name);

        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', function (e) {
          if (e.lengthComputable) {
            const currentTotalLoaded = totalUploadedBytes + e.loaded;
            const percent = Math.round((currentTotalLoaded / file.size) * 100);
            const elapsedTime = (new Date().getTime() - startTime) / 1000;
            const speedBytes = elapsedTime > 0 ? currentTotalLoaded / elapsedTime : 0;
            const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);

            if (progressFill) progressFill.style.width = percent + '%';
            if (statusText) statusText.textContent = `Parça ${currentChunk + 1}/${totalChunks} Yükleniyor: %${percent} (${speedMB} MB/s)`;
          }
        });

        xhr.onreadystatechange = function () {
          if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
              totalUploadedBytes += (end - start);
              currentChunk++;

              if (currentChunk < totalChunks) {
                sendNextChunk();
              } else {
                if (statusText) statusText.textContent = 'Tüm parçalar birleştirildi, yükleme başarılı!';
                if (progressFill) progressFill.style.width = '100%';
                setTimeout(() => {
                  if (progressBar) progressBar.style.display = 'none';
                  refreshAllFileLists();
                }, 1200);
              }
            } else {
              try {
                const resp = JSON.parse(xhr.responseText);
                if (statusText) statusText.textContent = 'Hata: ' + (resp.error || 'Parça yükleme başarısız.');
              } catch (err) {
                if (statusText) statusText.textContent = `Parça ${currentChunk + 1} yüklenirken sunucu hatası oluştu.`;
              }
            }
          }
        };

        xhr.open('POST', filehub_vars.rest_url + 'filehub/v1/upload-chunk', true);
        xhr.setRequestHeader('X-WP-Nonce', filehub_vars.nonce);
        xhr.send(formData);
      }

      sendNextChunk();
    }
  }

  // File Manager & Live Search — supports any number of file-list blocks on the same page
  // (e.g. the uploader shortcode shows the user's own files right below the dropzone).
  function fetchFileList(container) {
    if (!container) return;
    const scope = container.getAttribute('data-scope') || 'own';
    fetch(filehub_vars.rest_url + 'filehub/v1/files?scope=' + encodeURIComponent(scope), {
      headers: {
        'X-WP-Nonce': filehub_vars.nonce
      }
    })
      .then(res => res.json())
      .then(data => {
        if (!Array.isArray(data)) return;
        fileListCache.set(container, data);
        renderFileList(container, data);
      })
      .catch(err => console.error(err));
  }

  function refreshAllFileLists() {
    document.querySelectorAll('.filehub-file-list').forEach(fetchFileList);
  }

  function renderFileList(container, items) {
    if (!container) return;
    let html = '<div class="filehub-table-wrap"><table class="filehub-table"><thead><tr><th>Dosya Adı</th><th>Boyut</th><th>Sürücü</th><th>Yükleyen</th><th>İndirme</th><th>İşlem</th></tr></thead><tbody>';
    if (items.length === 0) {
      html += '<tr><td colspan="6" style="text-align:center; padding: 20px;">Dosya bulunamadı.</td></tr>';
    } else {
      items.forEach(item => {
        const deleteBtn = item.can_delete ? `<button type="button" class="button button-link-delete button-small filehub-delete-btn" data-id="${item.id}" style="color:#b32d2e; margin-left:8px;">Sil</button>` : '';
        html += `<tr>
          <td><strong>${escapeHtml(item.title)}</strong></td>
          <td>${item.file_size}</td>
          <td><span class="filehub-driver-badge">${item.driver}</span></td>
          <td>${escapeHtml(item.author_name)}</td>
          <td>${item.download_count}</td>
          <td>
            <a href="${item.download_url}" class="button button-secondary button-small" target="_blank">İndir</a>
            ${deleteBtn}
          </td>
        </tr>`;
      });
    }
    html += '</tbody></table></div>';
    container.innerHTML = html;

    // Attach Delete Event Listeners (scoped to this container only)
    container.querySelectorAll('.filehub-delete-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const fileId = this.getAttribute('data-id');
        if (confirm('Bu dosyayı kalıcı olarak silmek istediğinizden emin misiniz?')) {
          deleteFile(fileId, container);
        }
      });
    });
  }

  function deleteFile(fileId, container) {
    fetch(filehub_vars.rest_url + 'filehub/v1/files/' + fileId, {
      method: 'DELETE',
      headers: {
        'X-WP-Nonce': filehub_vars.nonce
      }
    })
      .then(res => res.json())
      .then(resp => {
        if (resp.success) {
          fetchFileList(container);
        } else {
          alert(resp.error || 'Silme işlemi başarısız.');
        }
      })
      .catch(err => alert('Sunucu bağlantı hatası.'));
  }

  document.querySelectorAll('.filehub-search-input').forEach(searchInput => {
    searchInput.addEventListener('input', function () {
      const scope = searchInput.closest('.filehub-uploader, .filehub-manager');
      const container = scope ? scope.querySelector('.filehub-file-list') : null;
      if (!container) return;

      const term = this.value.toLowerCase().trim();
      const cached = fileListCache.get(container) || [];
      const filtered = cached.filter(item =>
        item.title.toLowerCase().includes(term) || item.author_name.toLowerCase().includes(term)
      );
      renderFileList(container, filtered);
    });
  });

  // Front-End Password Change Handler
  if (passwordForm) {
    passwordForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const current_password = document.getElementById('filehub_current_password').value;
      const new_password = document.getElementById('filehub_new_password').value;
      const confirm_password = document.getElementById('filehub_confirm_password').value;

      if (passwordStatus) passwordStatus.textContent = 'Şifre güncelleniyor...';

      fetch(filehub_vars.rest_url + 'filehub/v1/change-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': filehub_vars.nonce
        },
        body: JSON.stringify({
          current_password: current_password,
          new_password: new_password,
          confirm_password: confirm_password
        })
      })
        .then(res => res.json())
        .then(resp => {
          if (resp.success) {
            if (passwordStatus) {
              passwordStatus.style.color = '#00a32a';
              passwordStatus.textContent = resp.message;
            }
            passwordForm.reset();
          } else {
            if (passwordStatus) {
              passwordStatus.style.color = '#b32d2e';
              passwordStatus.textContent = resp.error || 'Güncelleme başarısız.';
            }
          }
        })
        .catch(err => {
          if (passwordStatus) {
            passwordStatus.style.color = '#b32d2e';
            passwordStatus.textContent = 'Sunucu bağlantı hatası.';
          }
        });
    });
  }

  // Front-End Registration Handler
  if (registerForm) {
    registerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const username = document.getElementById('filehub_reg_username').value;
      const email = document.getElementById('filehub_reg_email').value;
      const first_name = document.getElementById('filehub_reg_first_name').value;
      const last_name = document.getElementById('filehub_reg_last_name').value;
      const password = document.getElementById('filehub_reg_password').value;
      const confirm_password = document.getElementById('filehub_reg_confirm_password').value;

      if (registerStatus) {
        registerStatus.style.color = '#1d2327';
        registerStatus.textContent = 'Kayıt işlemi yapılıyor...';
      }

      fetch(filehub_vars.rest_url + 'filehub/v1/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': filehub_vars.nonce
        },
        body: JSON.stringify({
          username: username,
          email: email,
          first_name: first_name,
          last_name: last_name,
          password: password,
          confirm_password: confirm_password
        })
      })
        .then(res => res.json())
        .then(resp => {
          if (resp.success) {
            if (registerStatus) {
              registerStatus.style.color = '#00a32a';
              registerStatus.textContent = resp.message;
            }
            registerForm.reset();
            setTimeout(() => {
              window.location.reload();
            }, 1500);
          } else {
            if (registerStatus) {
              registerStatus.style.color = '#b32d2e';
              registerStatus.textContent = resp.error || 'Kayıt başarısız.';
            }
          }
        })
        .catch(err => {
          if (registerStatus) {
            registerStatus.style.color = '#b32d2e';
            registerStatus.textContent = 'Sunucu bağlantı hatası.';
          }
        });
    });
  }

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  refreshAllFileLists();
});
