/**
 * Admin Panel JavaScript — vanilla JS, no jQuery
 * ================================================
 */

document.addEventListener('DOMContentLoaded', function () {

    /* ==========================================================
       1. SIDEBAR TOGGLE (mobile)
       ========================================================== */
    const sidebar       = document.querySelector('.admin-sidebar');
    const hamburgerBtn  = document.querySelector('.hamburger-btn');
    let backdrop        = document.querySelector('.sidebar-backdrop');

    if (sidebar && hamburgerBtn) {
        // Create backdrop if it doesn't exist in the DOM
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }

        hamburgerBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        });

        backdrop.addEventListener('click', function () {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        });
    }

    /* ==========================================================
       2. DELETE CONFIRMATION
       ========================================================== */
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('确定要删除吗？')) {
                e.preventDefault();
            }
        });
    });

    /* ==========================================================
       3. DYNAMIC WHOLESALE PRICE ROWS
       ========================================================== */
    const wholesaleContainer = document.querySelector('.wholesale-price-rows');
    const addWholesaleBtn    = document.querySelector('.btn-add-wholesale');

    if (wholesaleContainer && addWholesaleBtn) {
        addWholesaleBtn.addEventListener('click', function () {
            const index = wholesaleContainer.querySelectorAll('.wholesale-row').length;
            const row   = document.createElement('div');
            row.className = 'wholesale-row';
            row.innerHTML =
                '<input type="number" name="wholesale[' + index + '][min_quantity]" ' +
                       'class="form-control" placeholder="最小数量" min="1">' +
                '<input type="number" name="wholesale[' + index + '][price]" ' +
                       'class="form-control" placeholder="价格" step="0.01" min="0">' +
                '<button type="button" class="btn-remove-row" title="删除">&times;</button>';
            wholesaleContainer.appendChild(row);
        });

        // Event delegation for remove buttons
        wholesaleContainer.addEventListener('click', function (e) {
            if (e.target.classList.contains('btn-remove-row')) {
                e.target.closest('.wholesale-row').remove();
            }
        });
    }

    /* ==========================================================
       4. AUTO-GENERATE SLUG FROM NAME
       ========================================================== */
    const nameInput = document.querySelector('input[name="name"]');
    const slugInput = document.querySelector('input[name="slug"]');

    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function () {
            var value = this.value
                .toLowerCase()
                .replace(/[一-鿿㐀-䶿豈-﫿]/g, '') // remove CJK characters
                .replace(/[^\w\s-]/g, '')    // remove non-word chars except spaces & hyphens
                .replace(/\s+/g, '-')        // spaces to hyphens
                .replace(/-+/g, '-')         // collapse multiple hyphens
                .replace(/^-|-$/g, '');       // trim leading/trailing hyphens
            slugInput.value = value;
        });
    }

    /* ==========================================================
       5. AUTO-GENERATE COUPON CODE
       ========================================================== */
    const generateCodeBtn = document.getElementById('generate-code');
    const codeInput       = document.querySelector('input[name="code"]');

    if (generateCodeBtn && codeInput) {
        generateCodeBtn.addEventListener('click', function () {
            var chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var code   = '';
            for (var i = 0; i < 8; i++) {
                code += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            codeInput.value = code;
        });
    }

    /* ==========================================================
       6. MARKDOWN PREVIEW TOGGLE
       ========================================================== */
    const previewToggle = document.getElementById('preview-toggle');

    if (previewToggle) {
        previewToggle.addEventListener('click', function () {
            var textarea   = this.closest('.form-group, .mb-3, .form-section')
                                 ?.querySelector('textarea');
            var previewDiv = this.closest('.form-group, .mb-3, .form-section')
                                 ?.querySelector('.markdown-preview');

            if (!textarea) return;

            // Create preview div if it doesn't exist
            if (!previewDiv) {
                previewDiv = document.createElement('div');
                previewDiv.className = 'markdown-preview';
                previewDiv.style.display = 'none';
                textarea.parentNode.insertBefore(previewDiv, textarea.nextSibling);
            }

            var isHidden = previewDiv.style.display === 'none';

            if (isHidden) {
                previewDiv.innerHTML = convertMarkdown(textarea.value);
                previewDiv.style.display = 'block';
                textarea.style.display   = 'none';
                this.textContent = '编辑';
            } else {
                previewDiv.style.display = 'none';
                textarea.style.display   = '';
                this.textContent = '预览';
            }
        });
    }

    /**
     * Basic Markdown to HTML converter.
     */
    function convertMarkdown(md) {
        if (!md) return '<p style="color:#94a3b8;">暂无内容</p>';

        var html = md
            // escape HTML entities
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // headers (### before ## before #)
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm,  '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm,   '<h1>$1</h1>');

        // bold & italic
        html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

        // links [text](url)
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

        // unordered list items
        html = html.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');

        // wrap consecutive <li> in <ul>
        html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');

        // paragraphs: remaining lines separated by double newlines
        html = html.split(/\n{2,}/).map(function (block) {
            block = block.trim();
            if (!block) return '';
            // Don't wrap blocks that are already HTML block elements
            if (/^<(h[1-6]|ul|ol|li|blockquote|div|p)/.test(block)) return block;
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('\n');

        return html;
    }

    /* ==========================================================
       7. DASHBOARD CHART RENDERING (Canvas 2D)
       ========================================================== */
    window.renderRevenueChart = function (canvasId, labels, data) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !canvas.getContext) return;

        var ctx = canvas.getContext('2d');

        // Responsive sizing
        var container = canvas.parentElement;
        var dpr       = window.devicePixelRatio || 1;
        var width     = container.clientWidth;
        var height    = Math.min(350, width * 0.55);

        canvas.width  = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width  = width + 'px';
        canvas.style.height = height + 'px';
        ctx.scale(dpr, dpr);

        // Layout
        var padding    = { top: 20, right: 20, bottom: 50, left: 60 };
        var chartW     = width - padding.left - padding.right;
        var chartH     = height - padding.top - padding.bottom;
        var barColor   = '#3b82f6';
        var gridColor  = '#e2e8f0';
        var textColor  = '#64748b';
        var maxVal     = Math.max.apply(null, data) || 1;

        // Round max up to a nice number
        var magnitude = Math.pow(10, Math.floor(Math.log10(maxVal)));
        var niceMax   = Math.ceil(maxVal / magnitude) * magnitude;
        if (niceMax === 0) niceMax = 10;
        var gridLines = 5;

        // Clear
        ctx.clearRect(0, 0, width, height);

        // Grid lines + Y labels
        ctx.strokeStyle = gridColor;
        ctx.lineWidth   = 1;
        ctx.fillStyle   = textColor;
        ctx.font        = '11px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign   = 'right';
        ctx.textBaseline = 'middle';

        for (var i = 0; i <= gridLines; i++) {
            var y   = padding.top + chartH - (i / gridLines) * chartH;
            var val = (i / gridLines) * niceMax;

            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(padding.left + chartW, y);
            ctx.stroke();

            var label = val >= 10000 ? (val / 10000).toFixed(val % 10000 === 0 ? 0 : 1) + '万' :
                        val >= 1000  ? (val / 1000).toFixed(val % 1000 === 0 ? 0 : 1) + 'k' :
                        val.toFixed(0);
            ctx.fillText(label, padding.left - 8, y);
        }

        // Bars
        var barCount   = labels.length;
        var totalGap   = chartW * 0.3;
        var gap        = totalGap / (barCount + 1);
        var barWidth   = (chartW - totalGap) / barCount;
        var radius     = Math.min(4, barWidth * 0.15);

        for (var j = 0; j < barCount; j++) {
            var barH = (data[j] / niceMax) * chartH;
            var x    = padding.left + gap + j * (barWidth + gap);
            var barY = padding.top + chartH - barH;

            // Draw bar with rounded top
            ctx.fillStyle = barColor;
            ctx.beginPath();

            if (barH > radius * 2) {
                ctx.moveTo(x, padding.top + chartH);
                ctx.lineTo(x, barY + radius);
                ctx.arcTo(x, barY, x + radius, barY, radius);
                ctx.lineTo(x + barWidth - radius, barY);
                ctx.arcTo(x + barWidth, barY, x + barWidth, barY + radius, radius);
                ctx.lineTo(x + barWidth, padding.top + chartH);
            } else {
                // Bar too short for rounded corners
                ctx.rect(x, barY, barWidth, barH);
            }

            ctx.fill();

            // X-axis label
            ctx.fillStyle    = textColor;
            ctx.textAlign    = 'center';
            ctx.textBaseline = 'top';
            ctx.font         = '11px -apple-system, BlinkMacSystemFont, sans-serif';
            ctx.fillText(labels[j], x + barWidth / 2, padding.top + chartH + 8);
        }

        // X-axis line
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth   = 1;
        ctx.beginPath();
        ctx.moveTo(padding.left, padding.top + chartH);
        ctx.lineTo(padding.left + chartW, padding.top + chartH);
        ctx.stroke();
    };

    /* ==========================================================
       8. FLASH MESSAGE AUTO-DISMISS
       ========================================================== */
    document.querySelectorAll('.flash-message').forEach(function (msg) {
        // Close button
        var closeBtn = msg.querySelector('.flash-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                dismissFlash(msg);
            });
        }

        // Auto-dismiss after 5 seconds
        setTimeout(function () {
            dismissFlash(msg);
        }, 5000);
    });

    function dismissFlash(el) {
        if (!el || el.classList.contains('fade-out')) return;
        el.classList.add('fade-out');
        setTimeout(function () {
            el.remove();
        }, 400);
    }

    /* ==========================================================
       9. SELECT ALL / DESELECT ALL
       ========================================================== */
    var selectAllCheckbox = document.getElementById('select-all');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            var checked = this.checked;
            document.querySelectorAll('.card-checkbox').forEach(function (cb) {
                cb.checked = checked;
            });
            updateSelectedCount();
        });

        // Update "select all" when individual checkboxes change
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('card-checkbox')) {
                var all     = document.querySelectorAll('.card-checkbox');
                var checked = document.querySelectorAll('.card-checkbox:checked');
                selectAllCheckbox.checked       = all.length > 0 && checked.length === all.length;
                selectAllCheckbox.indeterminate  = checked.length > 0 && checked.length < all.length;
                updateSelectedCount();
            }
        });
    }

    function updateSelectedCount() {
        var countEl = document.querySelector('.selected-count');
        if (countEl) {
            var n = document.querySelectorAll('.card-checkbox:checked').length;
            countEl.textContent = '已选择 ' + n + ' 项';
        }
    }

    /* ==========================================================
       10. FILE UPLOAD PREVIEW
       ========================================================== */
    document.querySelectorAll('.image-upload').forEach(function (input) {
        input.addEventListener('change', function () {
            var previewContainer = this.parentElement.querySelector('.image-preview') ||
                                   this.closest('.form-group, .mb-3')?.querySelector('.image-preview');
            if (!previewContainer) return;

            var img = previewContainer.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.alt = '预览';
                previewContainer.appendChild(img);
            }

            if (this.files && this.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                    previewContainer.style.display = '';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    /* ==========================================================
       11. BATCH DELETE
       ========================================================== */
    var batchDeleteBtn = document.getElementById('batch-delete');

    if (batchDeleteBtn) {
        batchDeleteBtn.addEventListener('click', function () {
            var ids = [];
            document.querySelectorAll('.card-checkbox:checked').forEach(function (cb) {
                ids.push(cb.value);
            });

            if (ids.length === 0) {
                alert('请至少选择一项');
                return;
            }

            if (!confirm('确定要删除选中的 ' + ids.length + ' 项吗？')) {
                return;
            }

            var form = document.getElementById('batch-delete-form');
            if (form) {
                // Clear old hidden inputs
                form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });

                // Add checked ids
                ids.forEach(function (id) {
                    var hidden  = document.createElement('input');
                    hidden.type  = 'hidden';
                    hidden.name  = 'ids[]';
                    hidden.value = id;
                    form.appendChild(hidden);
                });

                form.submit();
            }
        });
    }

    /* ==========================================================
       12. CLOSE ORDER CONFIRMATION
       ========================================================== */
    document.querySelectorAll('.close-order-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('确定要关闭此订单吗？')) {
                e.preventDefault();
            }
        });
    });

    /* ==========================================================
       13. MARK PAID CONFIRMATION
       ========================================================== */
    document.querySelectorAll('.mark-paid-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('确定要标记为已付款吗？')) {
                e.preventDefault();
            }
        });
    });

    /* ==========================================================
       14. SETTINGS TAB PERSISTENCE (URL hash)
       ========================================================== */
    var settingsTabs = document.querySelectorAll('.settings-tabs .nav-link');

    if (settingsTabs.length > 0) {
        // Restore active tab from hash
        var hash = window.location.hash;
        if (hash) {
            var targetTab = document.querySelector('.settings-tabs .nav-link[data-bs-target="' + hash + '"], ' +
                                                   '.settings-tabs .nav-link[href="' + hash + '"]');
            if (targetTab) {
                // Deactivate others
                settingsTabs.forEach(function (t) { t.classList.remove('active'); });
                document.querySelectorAll('.settings-panel, .tab-pane').forEach(function (p) {
                    p.classList.remove('show', 'active');
                    p.style.display = 'none';
                });

                // Activate target
                targetTab.classList.add('active');
                var panel = document.querySelector(hash);
                if (panel) {
                    panel.classList.add('show', 'active');
                    panel.style.display = '';
                }
            }
        }

        // Save tab to hash on click
        settingsTabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                var target = this.getAttribute('data-bs-target') || this.getAttribute('href');
                if (target && target.startsWith('#')) {
                    history.replaceState(null, '', target);
                }

                // Manual tab switching if not using Bootstrap JS
                if (!window.bootstrap) {
                    e.preventDefault();

                    settingsTabs.forEach(function (t) { t.classList.remove('active'); });
                    document.querySelectorAll('.settings-panel, .tab-pane').forEach(function (p) {
                        p.classList.remove('show', 'active');
                        p.style.display = 'none';
                    });

                    this.classList.add('active');
                    if (target) {
                        var panel = document.querySelector(target);
                        if (panel) {
                            panel.classList.add('show', 'active');
                            panel.style.display = '';
                        }
                    }
                }
            });
        });
    }

});
