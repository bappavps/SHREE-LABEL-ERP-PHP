document.addEventListener('DOMContentLoaded', function () {
    const viewer = document.getElementById('artwork-viewer');
    const wrapper = document.getElementById('artwork-wrapper');
    const pinsContainer = document.getElementById('pins-container');
    const interactionLayer = document.getElementById('interaction-layer');
    const selectionRect = document.getElementById('selection-rect');
    const drawingCanvas = document.getElementById('drawing-canvas');
    const markupLayer = document.getElementById('markup-layer');
    const commentBox = document.getElementById('new-comment-box');
    const commentTextarea = document.getElementById('comment-textarea');
    const saveCommentBtn = document.getElementById('save-comment');
    const cancelCommentBtn = document.getElementById('cancel-comment');
    const closeCommentBtn = document.getElementById('close-comment-box');
    const refInput = document.getElementById('ref-image-input');
    const refPreview = document.getElementById('ref-image-preview');
    const pdfCanvas = document.getElementById('pdf-canvas');
    const toolStatus = document.getElementById('tool-status-label');
    const toolGuide = document.getElementById('tool-guide-label');
    const reviewContainer = document.querySelector('.review-container');
    const historySidebar = document.getElementById('history-sidebar');
    const historyResizer = document.getElementById('history-resizer');
    const commentSidebar = document.querySelector('.comment-sidebar');
    const commentResizer = document.getElementById('comment-resizer');
    const colorInput = document.getElementById('tool-color-input');
    const colorPreview = document.getElementById('tool-color-preview');
    const toolbar = document.getElementById('main-toolbar');
    const toolbarHandle = document.getElementById('toolbar-handle');
    const rulerTop = viewer.querySelector('.ruler-top');
    const rulerLeft = viewer.querySelector('.ruler-left');
    const rulerGuidesLayer = document.getElementById('ruler-guides-layer');
    const modalOverlay = document.getElementById('custom-modal-overlay');
    const modalTitle = document.getElementById('custom-modal-title');
    const modalMessage = document.getElementById('custom-modal-message');
    const modalOk = document.getElementById('custom-modal-ok');
    const modalCancel = document.getElementById('custom-modal-cancel');
    const cvPopup   = document.getElementById('comment-view-popup');
    const cvpLabel  = document.getElementById('cvp-label');
    const cvpAuthor = document.getElementById('cvp-author');
    const cvpText   = document.getElementById('cvp-text');
    const cvpAttach = document.getElementById('cvp-attachment');
    const cvpTime   = document.getElementById('cvp-time');
    const cvpClose  = document.getElementById('cvp-close');

    if (!viewer || !wrapper || !pinsContainer || !interactionLayer || !commentBox || !commentTextarea || !saveCommentBtn || !cancelCommentBtn || !markupLayer) {
        return;
    }

    let currentTool = 'select';
    const toolColors = {
        area: '#8b5cf6',
        arrow: '#f97316',
        pen: '#0f766e',
        highlighter: '#eab308',
        point: '#0ea5e9'
    };
    const colorEditableTools = ['pen', 'highlighter', 'arrow'];
    let currentColor = toolColors.pen;
    let activeAnnotation = null;
    let isSelecting = false;
    let isDrawing = false;
    let isPanning = false;
    let suppressNextClick = false;
    let activePointerId = null;
    let resizePointerId = null;
    let startX = 0;
    let startY = 0;
    let currentStroke = [];
    let currentArrow = null;
    let lastPanClient = null;
    let draftMarkup = null;
    let toolbarDragPointerId = null;
    let toolbarDragOffsetX = 0;
    let toolbarDragOffsetY = 0;
    let activeGuide = null;
    let activeGuidePointerId = null;
    let activeResize = null;
    let mouseResizeActive = false;
    let spacePanActive = false;
    let spacePanPreviousTool = 'select';
    const markupRecords = [];
    const viewStateStorageKey = 'review-view-state-' + (window.projectToken || 'default');
    const viewRestoreOnceKey = viewStateStorageKey + '-restore-once';

    const panzoom = window.Panzoom ? Panzoom(wrapper, {
        maxScale: 8,
        minScale: 0.05,
        cursor: 'default'
    }) : null;

    if (panzoom) {
        viewer.addEventListener('wheel', panzoom.zoomWithWheel);
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function getToolCursor(tool) {
        const cursors = {
            select: 'default',
            point: 'pointer',
            area: 'crosshair',
            arrow: 'alias',
            pen: 'crosshair',
            highlighter: 'copy',
            pan: 'grab'
        };
        return cursors[tool] || 'crosshair';
    }

    function toPixels(point) {
        return {
            x: (point.x / 100) * wrapper.clientWidth,
            y: (point.y / 100) * wrapper.clientHeight
        };
    }

    function buildPathString(path) {
        if (!Array.isArray(path) || path.length === 0) return '';
        return path.map(function (point, index) {
            const px = toPixels(point);
            return (index === 0 ? 'M ' : 'L ') + px.x + ' ' + px.y;
        }).join(' ');
    }

    function buildArrowGeometry(start, end) {
        const s = toPixels(start);
        const e = toPixels(end);
        const angle = Math.atan2(e.y - s.y, e.x - s.x);
        const headLength = 16;
        const wingAngle = Math.PI / 7;
        const head1 = { x: e.x - headLength * Math.cos(angle - wingAngle), y: e.y - headLength * Math.sin(angle - wingAngle) };
        const head2 = { x: e.x - headLength * Math.cos(angle + wingAngle), y: e.y - headLength * Math.sin(angle + wingAngle) };
        return {
            visiblePath: 'M ' + s.x + ' ' + s.y + ' L ' + e.x + ' ' + e.y + ' M ' + head1.x + ' ' + head1.y + ' L ' + e.x + ' ' + e.y + ' L ' + head2.x + ' ' + head2.y,
            hitPath: 'M ' + s.x + ' ' + s.y + ' L ' + e.x + ' ' + e.y
        };
    }

    function getRelativePosition(clientX, clientY) {
        const wrapperRect = wrapper.getBoundingClientRect();
        const baseWidth = Math.max(1, wrapperRect.width);
        const baseHeight = Math.max(1, wrapperRect.height);

        // Use transformed wrapper bounds so pan/zoom and centering offsets stay aligned.
        const localX = clientX - wrapperRect.left;
        const localY = clientY - wrapperRect.top;

        const x = (localX / baseWidth) * 100;
        const y = (localY / baseHeight) * 100;
        return { x: clamp(x, -500, 500), y: clamp(y, -500, 500) };
    }

    function viewerLocalPosition(clientX, clientY) {
        const rect = viewer.getBoundingClientRect();
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    function createGuide(orientation, pos) {
        if (!rulerGuidesLayer) return null;
        const guide = document.createElement('div');
        guide.className = 'ruler-guide ' + orientation;
        guide.dataset.orientation = orientation;
        rulerGuidesLayer.appendChild(guide);
        positionGuide(guide, pos);
        return guide;
    }

    function positionGuide(guide, pos) {
        if (!guide) return;
        const orientation = guide.dataset.orientation;
        const rect = viewer.getBoundingClientRect();
        if (orientation === 'vertical') {
            const clampedX = clamp(pos, 24, rect.width);
            guide.style.left = clampedX + 'px';
            const pct = Math.round(((clampedX - 24) / Math.max(1, rect.width - 24)) * 100);
            guide.setAttribute('data-value', 'X ' + pct + '%');
        } else {
            const clampedY = clamp(pos, 24, rect.height);
            guide.style.top = clampedY + 'px';
            const pct = Math.round(((clampedY - 24) / Math.max(1, rect.height - 24)) * 100);
            guide.setAttribute('data-value', 'Y ' + pct + '%');
        }
    }

    function startGuideDrag(guide, pointerId) {
        activeGuide = guide;
        activeGuidePointerId = pointerId;
    }

    function stopGuideDrag() {
        activeGuide = null;
        activeGuidePointerId = null;
    }

    function updateActiveGuide(clientX, clientY) {
        if (!activeGuide) return;
        const local = viewerLocalPosition(clientX, clientY);
        if (activeGuide.dataset.orientation === 'vertical') positionGuide(activeGuide, local.x);
        else positionGuide(activeGuide, local.y);
    }

    function shouldRemoveGuide(guide, clientX, clientY) {
        const local = viewerLocalPosition(clientX, clientY);
        return guide.dataset.orientation === 'vertical' ? local.x < 20 : local.y < 20;
    }

    function resizeDrawingCanvas() {
        if (!drawingCanvas) return;
        drawingCanvas.width = wrapper.clientWidth;
        drawingCanvas.height = wrapper.clientHeight;
    }

    function showModalDialog(options) {
        const opts = options || {};
        return new Promise(function (resolve) {
            const isConfirm = !!opts.confirm;
            modalTitle.textContent = opts.title || (isConfirm ? 'Please Confirm' : 'Notice');
            modalMessage.textContent = opts.message || '';
            modalOk.textContent = opts.okText || (isConfirm ? 'Yes' : 'OK');
            modalCancel.textContent = opts.cancelText || 'Cancel';
            modalCancel.style.display = isConfirm ? 'inline-flex' : 'none';
            modalOverlay.classList.add('open');
            const onOk = () => { cleanup(true); };
            const onCancel = () => { cleanup(false); };
            const onOverlay = (e) => { if (e.target === modalOverlay && isConfirm) cleanup(false); };
            function cleanup(res) {
                modalOverlay.classList.remove('open');
                modalOk.removeEventListener('click', onOk);
                modalCancel.removeEventListener('click', onCancel);
                modalOverlay.removeEventListener('click', onOverlay);
                resolve(res);
            }
            modalOk.addEventListener('click', onOk);
            modalCancel.addEventListener('click', onCancel);
            modalOverlay.addEventListener('click', onOverlay);
        });
    }

    function notify(message) { return showModalDialog({ confirm: false, message: message }); }
    function confirmAction(message) { return showModalDialog({ confirm: true, message: message }); }

    function saveViewState() {
        if (!panzoom) return;
        const state = { scale: panzoom.getScale(), pan: panzoom.getPan() };
        if (toolbar) {
            const tLeft  = toolbar.style.left;
            const tTop   = toolbar.style.top;
            const tTrans = toolbar.style.transform;
            if (tLeft)  state.toolbarLeft  = tLeft;
            if (tTop)   state.toolbarTop   = tTop;
            if (tTrans !== undefined) state.toolbarTransform = tTrans;
        }
        try { localStorage.setItem(viewStateStorageKey, JSON.stringify(state)); } catch(e) {}
    }

    function restoreViewState() {
        if (!panzoom) return false;
        try {
            const state = JSON.parse(localStorage.getItem(viewStateStorageKey));
            if (state) {
                panzoom.zoom(state.scale, { animate: false });
                panzoom.pan(state.pan.x, state.pan.y, { animate: false });
                if (toolbar && state.toolbarLeft) {
                    toolbar.style.left      = state.toolbarLeft;
                    toolbar.style.top       = state.toolbarTop || '';
                    toolbar.style.transform = state.toolbarTransform !== undefined ? state.toolbarTransform : '';
                }
                return true;
            }
        } catch(e) {}
        return false;
    }

    function fitArtworkToViewer(attempt) {
        if (!panzoom) {
            return;
        }

        const tryCount = Number(attempt || 0);
        const maxRetries = 40;
        const media = wrapper.querySelector('#pdf-canvas, #artwork-img');
        const mediaNotReady = media && media.tagName === 'IMG' && !media.complete;
        const widthNotReady = wrapper.clientWidth < 600 || wrapper.clientHeight < 600;

        if ((mediaNotReady || widthNotReady) && tryCount < maxRetries) {
            window.setTimeout(function () {
                fitArtworkToViewer(tryCount + 1);
            }, 120);
            return;
        }

        const rulerOffset = 24;
        const safePad = 8;

        panzoom.reset();

        requestAnimationFrame(function () {
            const viewerRect = viewer.getBoundingClientRect();
            const baseWidth = Math.max(1, wrapper.clientWidth);
            const baseHeight = Math.max(1, wrapper.clientHeight);
            const availableWidth = Math.max(1, viewerRect.width - rulerOffset - (safePad * 2));
            const availableHeight = Math.max(1, viewerRect.height - rulerOffset - (safePad * 2));
            const targetScale = Math.max(0.05, Math.min(8, Math.min(availableWidth / baseWidth, availableHeight / baseHeight)));

            panzoom.zoom(targetScale, { animate: false });

            requestAnimationFrame(function () {
                const postRect = wrapper.getBoundingClientRect();
                const targetLeft = viewerRect.left + rulerOffset + safePad + Math.max(0, (availableWidth - postRect.width) / 2);
                const targetTop = viewerRect.top + rulerOffset + safePad + Math.max(0, (availableHeight - postRect.height) / 2);
                const dx = targetLeft - postRect.left;
                const dy = targetTop - postRect.top;
                const currentPan = panzoom.getPan ? (panzoom.getPan() || { x: 0, y: 0 }) : { x: 0, y: 0 };
                panzoom.pan(Number(currentPan.x || 0) + dx, Number(currentPan.y || 0) + dy, { animate: false });
            });
        });
    }

    function reloadWithViewState() {
        saveViewState();
        try { sessionStorage.setItem(viewRestoreOnceKey, '1'); } catch (e) {}
        window.location.reload();
    }

    function syncToolColorUi() {
        const activeColor = toolColors[currentTool] || currentColor || toolColors.pen;
        const canEditColor = colorEditableTools.includes(currentTool);
        currentColor = activeColor;

        if (colorInput) {
            colorInput.disabled = !canEditColor;
            colorInput.value = activeColor;
            colorInput.style.opacity = canEditColor ? '1' : '0.45';
            colorInput.style.cursor = canEditColor ? 'pointer' : 'not-allowed';
        }

        if (colorPreview) {
            colorPreview.style.background = activeColor;
            colorPreview.style.opacity = canEditColor ? '1' : '0.55';
        }
    }

    function updateDraftColor(nextColor) {
        if (!draftMarkup || !nextColor) {
            return;
        }
        if (!['pen', 'highlighter', 'arrow'].includes(draftMarkup.type)) {
            return;
        }

        draftMarkup.color = nextColor;
        if (activeAnnotation && activeAnnotation.type === draftMarkup.type && activeAnnotation.data) {
            try {
                const payload = JSON.parse(activeAnnotation.data);
                payload.color = nextColor;
                activeAnnotation.data = JSON.stringify(payload);
            } catch (e) { /* ignore */ }
        }
        redrawStoredMarkup();
    }

    function setActiveTool(tool) {
        currentTool = tool;
        document.querySelectorAll('.tool-btn[data-tool]').forEach(b => b.classList.toggle('active', b.getAttribute('data-tool') === tool));
        const cursor = getToolCursor(tool);
        viewer.style.cursor = cursor;
        wrapper.style.cursor = cursor;
        interactionLayer.style.cursor = cursor;
        if (panzoom) panzoom.setOptions({ disablePan: tool !== 'pan' });
        const drawingTools = ['pen', 'highlighter', 'arrow', 'area'];
        pinsContainer.classList.toggle('pins-pass-through', drawingTools.includes(tool));
        const toolNames = { select: 'Select', point: 'Add Point', area: 'Select Area', arrow: 'Arrow Comment', pen: 'Pen Markup', highlighter: 'Highlight', pan: 'Pan Tool' };
        const toolGuides = { select: 'Select tool keeps the canvas neutral for browsing and closing floating boxes.', point: 'Click on the artwork to place a pin and add a comment.', area: 'Click and drag to select an area and add a comment.', arrow: 'Click and drag to draw an arrow pointing to a specific area.', pen: 'Draw freehand on the artwork to mark areas.', highlighter: 'Draw freehand highlights over the artwork.', pan: 'Click and drag to pan the artwork. Use mouse wheel to zoom.' };
        if (toolStatus) toolStatus.textContent = toolNames[tool] || tool;
        if (toolGuide) toolGuide.textContent = toolGuides[tool] || '';
        syncToolColorUi();
    }

    function activateLinkedComment(id) {
        document.querySelectorAll('.active-link').forEach(n => n.classList.remove('active-link'));
        document.querySelectorAll('[data-id="' + id + '"]').forEach(n => n.classList.add('active-link'));
        const comment = document.querySelector('.comment-item[data-id="' + id + '"]');
        if (comment) comment.classList.add('active-link');
    }

    function clearLinkedComment() { document.querySelectorAll('.active-link').forEach(n => n.classList.remove('active-link')); }

    function appendSvgPath(pathData, color, width, opacity, className) {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', pathData);
        path.setAttribute('class', className);
        path.setAttribute('stroke', color);
        path.setAttribute('stroke-width', String(width));
        if (opacity != null) path.setAttribute('opacity', String(opacity));
        markupLayer.appendChild(path);
        return path;
    }

    function appendArrowCallout(start, end, text, color) {
        const rawText = String(text || '').trim().replace(/\s+/g, ' ');
        if (!rawText) {
            return;
        }

        const noteText = rawText.length > 34 ? rawText.slice(0, 31) + '...' : rawText;
        const startPx = toPixels(start);
        const endPx = toPixels(end);
        const paddingX = 7;
        const noteHeight = 20;
        const noteWidth = clamp(Math.round((noteText.length * 6.2) + (paddingX * 2)), 52, 220);
        const margin = 6;

        const dx = endPx.x - startPx.x;
        const dy = endPx.y - startPx.y;
        const len = Math.sqrt((dx * dx) + (dy * dy)) || 1;
        const ux = dx / len;
        const uy = dy / len;

        // Place the note just beside the arrow tip.
        const tipClearance = 14;
        const sideGap = 6;
        const anchorX = endPx.x + (ux * tipClearance);
        const anchorY = endPx.y + (uy * tipClearance);
        const preferredX = ux >= 0 ? (anchorX + sideGap) : (anchorX - noteWidth - sideGap);
        const preferredY = anchorY - (noteHeight / 2);

        const maxLeft = Math.max(margin, wrapper.clientWidth - noteWidth - margin);
        const maxTop = Math.max(margin, wrapper.clientHeight - noteHeight - margin);
        const boxX = clamp(preferredX, margin, maxLeft);
        const boxY = clamp(preferredY, margin, maxTop);

        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('class', 'arrow-note');

        const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        bg.setAttribute('class', 'arrow-note-bg');
        bg.setAttribute('x', String(boxX));
        bg.setAttribute('y', String(boxY));
        bg.setAttribute('rx', '8');
        bg.setAttribute('ry', '8');
        bg.setAttribute('width', String(noteWidth));
        bg.setAttribute('height', String(noteHeight));
        const noteColor = color || '#0f766e';
        bg.setAttribute('fill', noteColor);
        bg.style.fill = noteColor;

        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('class', 'arrow-note-text');
        label.setAttribute('x', String(boxX + paddingX));
        label.setAttribute('y', String(boxY + (noteHeight / 2)));
        label.textContent = noteText;

        group.appendChild(bg);
        group.appendChild(label);
        markupLayer.appendChild(group);
    }

    function redrawStoredMarkup() {
        markupLayer.innerHTML = '';
        markupRecords.forEach(r => renderRecord(r, true));
        if (draftMarkup) renderRecord(draftMarkup, false);
    }

    function renderRecord(record, includeHit) {
        if (record.type === 'arrow') {
            const geom = buildArrowGeometry(record.start, record.end);
            appendSvgPath(geom.visiblePath, record.color, 4, 1, 'markup-stroke markup-arrow');
            appendArrowCallout(record.start, record.end, record.text, record.color);
            if (includeHit) {
                const hit = appendSvgPath(geom.hitPath, 'transparent', 24, null, 'markup-hit');
                hit.dataset.id = String(record.id);
                hit.addEventListener('mouseenter', () => activateLinkedComment(record.id));
                hit.addEventListener('mouseleave', clearLinkedComment);
            }
        } else if (Array.isArray(record.path)) {
            const pathData = buildPathString(record.path);
            appendSvgPath(pathData, record.color, record.type === 'highlighter' ? 18 : 4, record.type === 'highlighter' ? 0.38 : 1, record.type === 'pen' ? 'markup-stroke markup-pen' : 'markup-stroke');
            if (includeHit) {
                const hit = appendSvgPath(pathData, 'transparent', 28, null, 'markup-hit');
                hit.dataset.id = String(record.id);
                hit.addEventListener('mouseenter', () => activateLinkedComment(record.id));
                hit.addEventListener('mouseleave', clearLinkedComment);
            }
        }
    }

    if (toolbar && toolbarHandle) {
        toolbarHandle.addEventListener('pointerdown', function (e) {
            if (window.innerWidth <= 860) {
                return;
            }
            const toolbarRect = toolbar.getBoundingClientRect();
            const parentRect = (toolbar.offsetParent || viewer).getBoundingClientRect();
            toolbar.style.left = (toolbarRect.left - parentRect.left) + 'px';
            toolbar.style.top = (toolbarRect.top - parentRect.top) + 'px';
            toolbar.style.transform = 'none';

            toolbarDragPointerId = e.pointerId;
            toolbarDragOffsetX = e.clientX - toolbarRect.left;
            toolbarDragOffsetY = e.clientY - toolbarRect.top;
            toolbar.classList.add('dragging');
            toolbarHandle.setPointerCapture?.(e.pointerId);
            e.preventDefault();
        });

        toolbarHandle.addEventListener('pointermove', function (e) {
            if (toolbarDragPointerId !== e.pointerId) {
                return;
            }
            const parentRect = (toolbar.offsetParent || viewer).getBoundingClientRect();
            const boundsEl = toolbar.offsetParent || document.querySelector('.review-container') || viewer;
            const bounds = boundsEl.getBoundingClientRect();
            const toolbarRect = toolbar.getBoundingClientRect();
            const minLeft = (bounds.left - parentRect.left) + 8;
            const maxLeft = (bounds.right - parentRect.left) - toolbarRect.width - 8;
            const minTop = (bounds.top - parentRect.top) + 8;
            const maxTop = (bounds.bottom - parentRect.top) - toolbarRect.height - 8;
            const nextLeft = clamp((e.clientX - parentRect.left) - toolbarDragOffsetX, minLeft, maxLeft);
            const nextTop = clamp((e.clientY - parentRect.top) - toolbarDragOffsetY, minTop, maxTop);
            toolbar.style.left = nextLeft + 'px';
            toolbar.style.top = nextTop + 'px';
            e.preventDefault();
        });

        function stopToolbarDrag(e) {
            if (toolbarDragPointerId == null) {
                return;
            }
            if (e && e.pointerId != null && toolbarDragPointerId !== e.pointerId) {
                return;
            }
            toolbarDragPointerId = null;
            toolbar.classList.remove('dragging');
            if (e && e.pointerId != null) {
                toolbarHandle.releasePointerCapture?.(e.pointerId);
            }
        }

        toolbarHandle.addEventListener('pointerup', stopToolbarDrag);
        toolbarHandle.addEventListener('pointercancel', stopToolbarDrag);
        window.addEventListener('pointerup', stopToolbarDrag);
        window.addEventListener('blur', function () {
            toolbarDragPointerId = null;
            toolbar.classList.remove('dragging');
        });
    }

    function pxWidth(el, fallback) {
        if (!el) return fallback;
        const computed = parseFloat(getComputedStyle(el).width);
        return Number.isFinite(computed) ? computed : fallback;
    }

    function getResizeBounds() {
        const containerRect = (reviewContainer || viewer).getBoundingClientRect();
        const minHistoryWidth = 260;
        const minCommentWidth = 220;
        const maxCommentWidth = Math.min(560, containerRect.width - minHistoryWidth - 80);
        const currentHistoryWidth = pxWidth(historySidebar, minHistoryWidth);
        const maxHistoryWidth = Math.max(minHistoryWidth, containerRect.width - currentHistoryWidth - 80);
        return {
            containerRect,
            minHistoryWidth,
            minCommentWidth,
            maxHistoryWidth,
            maxCommentWidth
        };
    }

    function startSidebarResize(side, pointerId) {
        if (window.innerWidth <= 860) {
            return;
        }
        mouseResizeActive = pointerId == null;
        resizePointerId = pointerId;
        activeResize = side;
        if (commentSidebar) commentSidebar.classList.add('is-resizing');
        if (historySidebar) historySidebar.classList.add('is-resizing');
    }

    function stopSidebarResize(pointerId) {
        if (resizePointerId == null) {
            return;
        }
        if (pointerId != null && resizePointerId !== pointerId) {
            return;
        }
        mouseResizeActive = false;
        resizePointerId = null;
        activeResize = null;
        if (commentSidebar) commentSidebar.classList.remove('is-resizing');
        if (historySidebar) historySidebar.classList.remove('is-resizing');
    }

    if (commentResizer && commentSidebar) {
        commentResizer.addEventListener('pointerdown', function (e) {
            startSidebarResize('comment', e.pointerId);
            commentResizer.setPointerCapture?.(e.pointerId);
            e.preventDefault();
            e.stopPropagation();
        });
        commentResizer.addEventListener('mousedown', function (e) {
            startSidebarResize('comment', null);
            e.preventDefault();
            e.stopPropagation();
        });
        commentResizer.addEventListener('pointerup', function (e) {
            commentResizer.releasePointerCapture?.(e.pointerId);
            stopSidebarResize(e.pointerId);
        });
        commentResizer.addEventListener('pointercancel', function (e) {
            commentResizer.releasePointerCapture?.(e.pointerId);
            stopSidebarResize(e.pointerId);
        });
    }

    if (historyResizer && historySidebar) {
        historyResizer.addEventListener('pointerdown', function (e) {
            startSidebarResize('history', e.pointerId);
            historyResizer.setPointerCapture?.(e.pointerId);
            e.preventDefault();
            e.stopPropagation();
        });
        historyResizer.addEventListener('mousedown', function (e) {
            startSidebarResize('history', null);
            e.preventDefault();
            e.stopPropagation();
        });
        historyResizer.addEventListener('pointerup', function (e) {
            historyResizer.releasePointerCapture?.(e.pointerId);
            stopSidebarResize(e.pointerId);
        });
        historyResizer.addEventListener('pointercancel', function (e) {
            historyResizer.releasePointerCapture?.(e.pointerId);
            stopSidebarResize(e.pointerId);
        });
    }

    window.addEventListener('pointermove', function (e) {
        if (resizePointerId == null || resizePointerId !== e.pointerId || !activeResize) {
            return;
        }
        const bounds = getResizeBounds();
        if (activeResize === 'comment' && commentSidebar) {
            const panelRight = commentSidebar.getBoundingClientRect().right;
            const requested = panelRight - e.clientX;
            const next = clamp(requested, bounds.minCommentWidth, bounds.maxCommentWidth);
            commentSidebar.style.width = next + 'px';
        } else if (activeResize === 'history' && historySidebar) {
            const requested = e.clientX - bounds.containerRect.left;
            const next = clamp(requested, bounds.minHistoryWidth, bounds.maxHistoryWidth);
            historySidebar.style.width = next + 'px';
        }
        e.preventDefault();
    });

    window.addEventListener('mousemove', function (e) {
        if (!mouseResizeActive || !activeResize) {
            return;
        }
        const bounds = getResizeBounds();
        if (activeResize === 'comment' && commentSidebar) {
            const panelRight = commentSidebar.getBoundingClientRect().right;
            const requested = panelRight - e.clientX;
            const next = clamp(requested, bounds.minCommentWidth, bounds.maxCommentWidth);
            commentSidebar.style.width = next + 'px';
        } else if (activeResize === 'history' && historySidebar) {
            const requested = e.clientX - bounds.containerRect.left;
            const next = clamp(requested, bounds.minHistoryWidth, bounds.maxHistoryWidth);
            historySidebar.style.width = next + 'px';
        }
    });

    window.addEventListener('pointerup', function (e) {
        stopSidebarResize(e.pointerId);
    });

    window.addEventListener('pointercancel', function (e) {
        stopSidebarResize(e.pointerId);
    });

    window.addEventListener('mouseup', function () {
        if (mouseResizeActive) {
            stopSidebarResize(null);
        }
    });

    window.addEventListener('blur', function () {
        stopSidebarResize(null);
    });

    // Toggle comments panel
    const toggleCommentsBtn = document.getElementById('toggle-comments-btn');
    if (toggleCommentsBtn && commentSidebar) {
        toggleCommentsBtn.addEventListener('click', function () {
            const isHidden = commentSidebar.classList.toggle('panel-hidden');
            toggleCommentsBtn.classList.toggle('active', !isHidden);
        });
    }

    setActiveTool('select');

    if (colorInput) {
        colorInput.addEventListener('input', function () {
            if (!colorEditableTools.includes(currentTool)) {
                return;
            }
            const next = colorInput.value;
            toolColors[currentTool] = next;
            currentColor = next;
            if (colorPreview) {
                colorPreview.style.background = next;
            }
            updateDraftColor(next);
        });
    }

    document.querySelectorAll('.tool-btn[data-tool]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setActiveTool(btn.getAttribute('data-tool'));
        });
    });

    const zoomIn = document.getElementById('zoom-in');
    const zoomOut = document.getElementById('zoom-out');
    const zoomReset = document.getElementById('zoom-reset');
    if (zoomIn && panzoom) zoomIn.addEventListener('click', function () { panzoom.zoomIn(); });
    if (zoomOut && panzoom) zoomOut.addEventListener('click', function () { panzoom.zoomOut(); });
    if (zoomReset && panzoom) {
        zoomReset.addEventListener('click', function () {
            panzoom.reset();
            saveViewState();
        });
    }

    wrapper.addEventListener('pointerdown', function () {
        if (currentTool === 'pan') {
            wrapper.style.cursor = 'grabbing';
            viewer.style.cursor = 'grabbing';
        }
    });

    wrapper.addEventListener('pointerup', function () {
        if (currentTool === 'pan') {
            const cursor = getToolCursor('pan');
            wrapper.style.cursor = cursor;
            viewer.style.cursor = cursor;
        }
    });

    if (rulerTop) {
        rulerTop.addEventListener('pointerdown', function (e) {
            if (window.innerWidth <= 860) {
                return;
            }
            const local = viewerLocalPosition(e.clientX, e.clientY);
            const guide = createGuide('horizontal', local.y);
            if (!guide) {
                return;
            }
            startGuideDrag(guide, e.pointerId);
            e.preventDefault();
        });
    }

    if (rulerLeft) {
        rulerLeft.addEventListener('pointerdown', function (e) {
            if (window.innerWidth <= 860) {
                return;
            }
            const local = viewerLocalPosition(e.clientX, e.clientY);
            const guide = createGuide('vertical', local.x);
            if (!guide) {
                return;
            }
            startGuideDrag(guide, e.pointerId);
            e.preventDefault();
        });
    }

    if (rulerGuidesLayer) {
        rulerGuidesLayer.addEventListener('pointerdown', function (e) {
            const guide = e.target.closest('.ruler-guide');
            if (!guide) {
                return;
            }
            startGuideDrag(guide, e.pointerId);
            e.preventDefault();
        });
    }

    window.addEventListener('pointermove', function (e) {
        if (activeGuidePointerId == null || activeGuidePointerId !== e.pointerId) {
            return;
        }
        updateActiveGuide(e.clientX, e.clientY);
    });

    window.addEventListener('pointerup', function (e) {
        if (activeGuidePointerId == null || activeGuidePointerId !== e.pointerId || !activeGuide) {
            return;
        }
        if (shouldRemoveGuide(activeGuide, e.clientX, e.clientY)) {
            activeGuide.remove();
        }
        stopGuideDrag();
    });

    window.addEventListener('pointercancel', function (e) {
        if (activeGuidePointerId == null || activeGuidePointerId !== e.pointerId) {
            return;
        }
        stopGuideDrag();
    });

    window.addEventListener('blur', stopGuideDrag);

    interactionLayer.addEventListener('pointerdown', function (e) {
        if (e.target.closest('.toolbar')) {
            return;
        }
        const drawingTools = ['pen', 'highlighter', 'arrow', 'area'];
        if (!drawingTools.includes(currentTool)) {
            if (e.target.closest('.pin') || e.target.closest('.comment-area')) {
                return;
            }
        }
        if (interactionLayer.dataset.readonly === '1') { return; }

        if (currentTool === 'pan') {
            e.preventDefault();
            isPanning = true;
            activePointerId = e.pointerId;
            lastPanClient = { x: e.clientX, y: e.clientY };
            interactionLayer.setPointerCapture?.(e.pointerId);
            wrapper.style.cursor = 'grabbing';
            viewer.style.cursor = 'grabbing';
            return;
        }

        if (currentTool === 'area') {
            e.preventDefault();
            activePointerId = e.pointerId;
            const pos = getRelativePosition(e.clientX, e.clientY);
            startX = pos.x;
            startY = pos.y;
            isSelecting = true;
            suppressNextClick = true;
            interactionLayer.setPointerCapture?.(e.pointerId);

            selectionRect.style.display = 'block';
            selectionRect.style.left = startX + '%';
            selectionRect.style.top = startY + '%';
            selectionRect.style.width = '0%';
            selectionRect.style.height = '0%';
            return;
        }

        if (currentTool === 'pen' || currentTool === 'highlighter') {
            e.preventDefault();
            activePointerId = e.pointerId;
            suppressNextClick = true;
            isDrawing = true;
            interactionLayer.setPointerCapture?.(e.pointerId);
            const pos = getRelativePosition(e.clientX, e.clientY);
            currentStroke = [{ x: pos.x, y: pos.y }];
            drawLiveStroke();
            return;
        }

        if (currentTool === 'arrow') {
            e.preventDefault();
            activePointerId = e.pointerId;
            suppressNextClick = true;
            isDrawing = true;
            const pos = getRelativePosition(e.clientX, e.clientY);
            currentArrow = {
                start: { x: pos.x, y: pos.y },
                end: { x: pos.x, y: pos.y }
            };
            interactionLayer.setPointerCapture?.(e.pointerId);
            drawLiveArrow();
        }
    });

    interactionLayer.addEventListener('click', function (e) {
        if (suppressNextClick) {
            suppressNextClick = false;
            return;
        }

        if (currentTool !== 'point') {
            return;
        }

        if (e.target.closest('.pin') || e.target.closest('.comment-area') || e.target.closest('.toolbar') || e.target.closest('#new-comment-box') || e.target.closest('#comment-view-popup')) {
            return;
        }

        const pos = getRelativePosition(e.clientX, e.clientY);
        if (!Number.isFinite(pos.x) || !Number.isFinite(pos.y) || pos.x < 0 || pos.x > 100 || pos.y < 0 || pos.y > 100) {
            return;
        }

        activeAnnotation = {
            type: 'point',
            x: pos.x,
            y: pos.y,
            data: ''
        };
        createTempPin(pos.x, pos.y, 'point');
        openCommentComposer();
    });

    interactionLayer.addEventListener('pointermove', function (e) {
        if (currentTool === 'pan' && isPanning && activePointerId === e.pointerId && panzoom && lastPanClient) {
            e.preventDefault();
            const dx = e.clientX - lastPanClient.x;
            const dy = e.clientY - lastPanClient.y;
            if (typeof panzoom.pan === 'function') {
                panzoom.pan(dx, dy, { relative: true, animate: false });
            }
            lastPanClient = { x: e.clientX, y: e.clientY };
            return;
        }

        if (currentTool === 'area' && isSelecting && activePointerId === e.pointerId && selectionRect) {
            e.preventDefault();
            const pos = getRelativePosition(e.clientX, e.clientY);
            const left = Math.min(startX, pos.x);
            const top = Math.min(startY, pos.y);
            const width = Math.abs(pos.x - startX);
            const height = Math.abs(pos.y - startY);
            selectionRect.style.left = left + '%';
            selectionRect.style.top = top + '%';
            selectionRect.style.width = width + '%';
            selectionRect.style.height = height + '%';
            return;
        }

        if ((currentTool === 'pen' || currentTool === 'highlighter') && isDrawing && activePointerId === e.pointerId) {
            e.preventDefault();
            const pos = getRelativePosition(e.clientX, e.clientY);
            currentStroke.push({ x: pos.x, y: pos.y });
            drawLiveStroke();
            return;
        }

        if (currentTool === 'arrow' && isDrawing && activePointerId === e.pointerId && currentArrow) {
            e.preventDefault();
            const pos = getRelativePosition(e.clientX, e.clientY);
            currentArrow.end = { x: pos.x, y: pos.y };
            drawLiveArrow();
        }
    });

    interactionLayer.addEventListener('pointerup', function (e) {
        if (currentTool === 'pan' && isPanning && activePointerId === e.pointerId) {
            isPanning = false;
            activePointerId = null;
            lastPanClient = null;
            interactionLayer.releasePointerCapture?.(e.pointerId);
            const panCursor = getToolCursor('pan');
            wrapper.style.cursor = panCursor;
            viewer.style.cursor = panCursor;
            return;
        }

        if (currentTool === 'area' && isSelecting && activePointerId === e.pointerId) {
            e.preventDefault();
            isSelecting = false;
            activePointerId = null;
            interactionLayer.releasePointerCapture?.(e.pointerId);

            const pos = getRelativePosition(e.clientX, e.clientY);
            const left = Math.min(startX, pos.x);
            const top = Math.min(startY, pos.y);
            const width = Math.abs(pos.x - startX);
            const height = Math.abs(pos.y - startY);

            if (width < 1 || height < 1) {
                selectionRect.style.display = 'none';
                suppressNextClick = false;
                return;
            }

            activeAnnotation = {
                type: 'area',
                x: left,
                y: top,
                w: width,
                h: height,
                data: ''
            };
            openCommentComposer();
            return;
        }

        if ((currentTool === 'pen' || currentTool === 'highlighter') && isDrawing && activePointerId === e.pointerId) {
            e.preventDefault();
            isDrawing = false;
            activePointerId = null;
            interactionLayer.releasePointerCapture?.(e.pointerId);

            if (currentStroke.length < 2) {
                currentStroke = [];
                redrawStoredMarkup();
                suppressNextClick = false;
                return;
            }

            const startPoint = currentStroke[0];
            draftMarkup = {
                type: currentTool,
                color: currentColor,
                path: currentStroke.slice()
            };

            activeAnnotation = {
                type: currentTool,
                x: startPoint.x,
                y: startPoint.y,
                data: JSON.stringify({
                    tool: currentTool,
                    color: currentColor,
                    path: currentStroke
                })
            };
            createTempPin(startPoint.x, startPoint.y, currentTool);
            redrawStoredMarkup();
            openCommentComposer();
            return;
        }

        if (currentTool === 'arrow' && isDrawing && activePointerId === e.pointerId && currentArrow) {
            e.preventDefault();
            isDrawing = false;
            activePointerId = null;
            interactionLayer.releasePointerCapture?.(e.pointerId);

            const dx = currentArrow.end.x - currentArrow.start.x;
            const dy = currentArrow.end.y - currentArrow.start.y;
            const distance = Math.sqrt((dx * dx) + (dy * dy));
            if (distance < 1) {
                currentArrow = null;
                redrawStoredMarkup();
                suppressNextClick = false;
                return;
            }

            draftMarkup = {
                type: 'arrow',
                color: currentColor,
                start: { x: currentArrow.start.x, y: currentArrow.start.y },
                end: { x: currentArrow.end.x, y: currentArrow.end.y },
                text: ''
            };

            activeAnnotation = {
                type: 'arrow',
                x: currentArrow.end.x,
                y: currentArrow.end.y,
                data: JSON.stringify({
                    color: currentColor,
                    start: currentArrow.start,
                    end: currentArrow.end,
                    text: ''
                })
            };
            createTempPin(currentArrow.end.x, currentArrow.end.y, 'arrow');
            redrawStoredMarkup();
            openCommentComposer();
        }
    });

    interactionLayer.addEventListener('pointercancel', function (e) {
        if (activePointerId !== e.pointerId) {
            return;
        }
        activePointerId = null;
        isSelecting = false;
        isDrawing = false;
        isPanning = false;
        suppressNextClick = false;
        currentStroke = [];
        currentArrow = null;
        lastPanClient = null;
        if (selectionRect) {
            selectionRect.style.display = 'none';
        }
        interactionLayer.releasePointerCapture?.(e.pointerId);
        redrawStoredMarkup();
    });

    if (refInput && refPreview) {
        refInput.addEventListener('change', function () {
            const file = refInput.files && refInput.files[0] ? refInput.files[0] : null;
            if (!file || !file.type.startsWith('image/')) {
                refPreview.style.display = 'none';
                refPreview.src = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (event) {
                refPreview.src = event.target.result;
                refPreview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }

    // ── Helper: create or remove temporary pin marker ──────────────────
    function createTempPin(x, y, type) {
        if (type === 'arrow') {
            document.querySelectorAll('.temp-pin').forEach(function (el) { el.remove(); });
            return;
        }
        document.querySelectorAll('.temp-pin').forEach(function (el) { el.remove(); });
        const pin = document.createElement('div');
        pin.className = 'pin temp-pin';
        pin.dataset.type = type || 'point';
        pin.style.left = x + '%';
        pin.style.top  = y + '%';
        pin.textContent = '?';
        pinsContainer.appendChild(pin);
    }

    // ── Helper: show the comment composer panel ──────────────────────────
    function openCommentComposer() {
        commentTextarea.value = '';
        if (refInput) refInput.value = '';
        if (refPreview) { refPreview.src = ''; refPreview.style.display = 'none'; }
        commentBox.style.display = 'block';
        positionCommentBox();
        commentTextarea.focus();
    }

    function positionCommentBox() {
        if (!activeAnnotation || !reviewContainer) return;
        const containerRect = reviewContainer.getBoundingClientRect();
        const wrapperRect = wrapper.getBoundingClientRect();

        // Annotation x/y are percentages of the wrapper
        const xPx = wrapperRect.left - containerRect.left + (activeAnnotation.x / 100) * wrapperRect.width;
        const yPx = wrapperRect.top  - containerRect.top  + (activeAnnotation.y / 100) * wrapperRect.height;

        const boxW = commentBox.offsetWidth  || 300;
        const boxH = commentBox.offsetHeight || 230;
        const gap  = 16;
        const cW   = containerRect.width;
        const cH   = containerRect.height;

        // Default: popup to the right of the pin; flip left if near right edge
        let left = xPx + gap;
        if (left + boxW > cW - 8) left = xPx - boxW - gap;
        left = Math.max(8, left);

        // Vertically: align top with pin; push up if near bottom
        let top = yPx - 16;
        if (top + boxH > cH - 8) top = cH - boxH - 8;
        top = Math.max(8, top);

        commentBox.style.left = left + 'px';
        commentBox.style.top  = top  + 'px';
    }

    function positionFloatingEl(el, anchorEl) {
        if (!reviewContainer) return;
        const containerRect = reviewContainer.getBoundingClientRect();
        const anchorRect    = anchorEl.getBoundingClientRect();
        const elW = el.offsetWidth  || 300;
        const elH = el.offsetHeight || 160;
        const gap = 12;
        const cW  = containerRect.width;
        const cH  = containerRect.height;
        const ax  = anchorRect.left - containerRect.left + anchorRect.width / 2;
        const ay  = anchorRect.top  - containerRect.top  + anchorRect.height / 2;

        let left = ax + gap;
        if (left + elW > cW - 8) left = ax - elW - gap;
        left = Math.max(8, left);

        let top = ay - 16;
        if (top + elH > cH - 8) top = cH - elH - 8;
        top = Math.max(8, top);

        el.style.left = left + 'px';
        el.style.top  = top  + 'px';
    }

    function openCommentViewPopup(id, anchorEl) {
        const popup   = document.getElementById('comment-view-popup');
        if (!popup) return;
        const comments = window.initialComments || [];
        const c = comments.find(function (x) { return String(x.id) === String(id); });
        if (!c) return;

        // Close composer if open
        resetCommentComposer();

        const idx    = comments.indexOf(c);
        const lbl    = document.getElementById('cvp-label');
        const author = document.getElementById('cvp-author');
        const text   = document.getElementById('cvp-text');
        const time   = document.getElementById('cvp-time');
        const attach = document.getElementById('cvp-attachment');

        if (lbl)    lbl.textContent    = (c.type || 'point').charAt(0).toUpperCase() + (c.type || 'point').slice(1) + ' #' + (idx + 1);
        if (author) author.textContent = c.user_name || '';
        if (text)   text.textContent   = c.comment   || '';
        if (time)   time.textContent   = c.created_at ? new Date(c.created_at).toLocaleString() : '';
        if (attach) {
            attach.innerHTML = '';
            if (c.attachment) {
                const isImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(c.attachment);
                if (isImg) {
                    const img = document.createElement('img');
                    img.src = 'uploads/references/' + c.attachment;
                    img.style.cssText = 'max-width:100%;border-radius:8px;border:1px solid #e2e8f0;margin-top:0.4rem;cursor:pointer;';
                    img.onclick = function () { window.open(img.src); };
                    attach.appendChild(img);
                } else {
                    attach.innerHTML = '<a href="uploads/references/' + c.attachment + '" target="_blank" style="font-size:0.8rem;color:#1d4ed8;"><i class="fas fa-paperclip"></i> View Attachment</a>';
                }
            }
        }

        // Wire Delete button
        const deleteBtn = document.getElementById('cvp-delete');
        if (deleteBtn) {
            deleteBtn.onclick = function (e) {
                e.stopPropagation();
                confirmAction('Delete this comment?').then(function (ok) {
                    if (!ok) return;
                    const fd = new FormData();
                    fd.append('comment_id', c.id);
                    fd.append('token', window.projectToken);
                    fetch('api/delete-comment.php', { method: 'POST', body: fd })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.status !== 'success') { notify(data.message || 'Unable to delete comment.'); return; }
                            reloadWithViewState();
                        })
                        .catch(function () { notify('Failed to delete comment.'); });
                });
            };
        }

        // Wire Reply toggle
        const replyBox      = document.getElementById('cvp-reply-box');
        const replyTextarea = document.getElementById('cvp-reply-textarea');
        const replyToggle   = document.getElementById('cvp-reply-toggle');
        if (replyToggle && replyBox) {
            // Reset reply box state
            replyBox.style.display = 'none';
            if (replyTextarea) replyTextarea.value = '';
            replyToggle.onclick = function (e) {
                e.stopPropagation();
                const open = replyBox.style.display === 'block';
                replyBox.style.display = open ? 'none' : 'block';
                if (!open && replyTextarea) replyTextarea.focus();
            };
        }

        // Wire Reply post
        const replyPostBtn = document.getElementById('cvp-reply-post');
        if (replyPostBtn && replyTextarea) {
            replyPostBtn.onclick = function (e) {
                e.stopPropagation();
                const message = replyTextarea.value.trim();
                if (!message) return;
                const fd = new FormData();
                fd.append('file_id', window.fileId);
                fd.append('comment', message);
                fd.append('parent_id', c.id);
                fd.append('user_name', window.clientName || 'Client');
                fetch('api/add-comment.php', { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.status !== 'success') { notify(data.message || 'Unable to send reply.'); return; }
                        reloadWithViewState();
                    })
                    .catch(function () { notify('Failed to send reply.'); });
            };
        }

        popup.style.display = 'block';
        positionFloatingEl(popup, anchorEl);
    }

    function closeCommentViewPopup() {
        const popup = document.getElementById('comment-view-popup');
        if (popup) popup.style.display = 'none';
    }

    const cvpCloseBtn = document.getElementById('cvp-close');
    if (cvpCloseBtn) {
        cvpCloseBtn.addEventListener('click', closeCommentViewPopup);
    }

    // Close view popup when clicking outside
    document.addEventListener('pointerdown', function (e) {
        const popup = document.getElementById('comment-view-popup');
        if (popup && popup.style.display !== 'none') {
            if (!popup.contains(e.target)) {
                closeCommentViewPopup();
            }
        }
    }, true);

    // ── Helper: hide / reset the comment composer panel ─────────────────
    function resetCommentComposer() {
        commentBox.style.display = 'none';
        commentTextarea.value = '';
        if (refInput) refInput.value = '';
        if (refPreview) { refPreview.src = ''; refPreview.style.display = 'none'; }
        document.querySelectorAll('.temp-pin').forEach(function (el) { el.remove(); });
        if (selectionRect) selectionRect.style.display = 'none';
        draftMarkup = null;
        activeAnnotation = null;
        currentStroke = [];
        currentArrow = null;
        redrawStoredMarkup();
    }

    // ── Helper: draw live pen/highlighter stroke on drawing-canvas ───────
    function drawLiveStroke() {
        if (!drawingCanvas || currentStroke.length < 1) return;
        const ctx = drawingCanvas.getContext('2d');
        if (!ctx) return;
        resizeDrawingCanvas();
        ctx.clearRect(0, 0, drawingCanvas.width, drawingCanvas.height);
        if (currentStroke.length < 2) return;
        const w = drawingCanvas.width;
        const h = drawingCanvas.height;
        ctx.beginPath();
        ctx.moveTo((currentStroke[0].x / 100) * w, (currentStroke[0].y / 100) * h);
        for (let i = 1; i < currentStroke.length; i++) {
            ctx.lineTo((currentStroke[i].x / 100) * w, (currentStroke[i].y / 100) * h);
        }
        ctx.strokeStyle = currentColor;
        ctx.lineWidth = currentTool === 'highlighter' ? 18 : 3;
        ctx.globalAlpha = currentTool === 'highlighter' ? 0.4 : 1;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke();
        ctx.globalAlpha = 1;
    }

    // ── Helper: draw live arrow preview on drawing-canvas ────────────────
    function drawLiveArrow() {
        if (!drawingCanvas || !currentArrow) return;
        const ctx = drawingCanvas.getContext('2d');
        if (!ctx) return;
        resizeDrawingCanvas();
        ctx.clearRect(0, 0, drawingCanvas.width, drawingCanvas.height);
        const w = drawingCanvas.width;
        const h = drawingCanvas.height;
        const sx = (currentArrow.start.x / 100) * w;
        const sy = (currentArrow.start.y / 100) * h;
        const ex = (currentArrow.end.x   / 100) * w;
        const ey = (currentArrow.end.y   / 100) * h;
        const angle = Math.atan2(ey - sy, ex - sx);
        const headLen = 14;
        ctx.beginPath();
        ctx.moveTo(sx, sy);
        ctx.lineTo(ex, ey);
        ctx.strokeStyle = currentColor;
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(ex, ey);
        ctx.lineTo(ex - headLen * Math.cos(angle - Math.PI / 6), ey - headLen * Math.sin(angle - Math.PI / 6));
        ctx.moveTo(ex, ey);
        ctx.lineTo(ex - headLen * Math.cos(angle + Math.PI / 6), ey - headLen * Math.sin(angle + Math.PI / 6));
        ctx.stroke();
    }

    function syncDraftCalloutText() {
        if (!draftMarkup || draftMarkup.type !== 'arrow') {
            return;
        }
        const text = commentTextarea.value.trim();
        draftMarkup.text = text;
        if (activeAnnotation && activeAnnotation.type === 'arrow' && activeAnnotation.data) {
            try {
                const payload = JSON.parse(activeAnnotation.data);
                payload.text = text;
                activeAnnotation.data = JSON.stringify(payload);
            } catch (e) { /* ignore */ }
        }
        redrawStoredMarkup();
    }

    function refreshCommentsWithoutReload() {
        if (!window.fileId || !window.projectToken) {
            return Promise.resolve(false);
        }

        return fetch('api/get-comments.php?file_id=' + encodeURIComponent(String(window.fileId)) + '&token=' + encodeURIComponent(String(window.projectToken)))
            .then(function (res) { return res.json(); })
            .then(function (resp) {
                if (!resp || resp.status !== 'success') {
                    return false;
                }

                const comments = (resp.data && Array.isArray(resp.data.comments)) ? resp.data.comments : [];
                window.initialComments = comments;

                if (typeof window.renderVersionComments === 'function') {
                    window.renderVersionComments(comments, true);
                } else if (typeof window.renderCommentsForVersion === 'function') {
                    window.renderCommentsForVersion(comments);
                }

                const countBadge = document.getElementById('comments-count-badge');
                if (countBadge) {
                    countBadge.textContent = String(comments.length);
                }

                return true;
            })
            .catch(function () {
                return false;
            });
    }

    commentTextarea.addEventListener('input', function () {
        syncDraftCalloutText();
    });

    function isTypingContext(target) {
        if (!target) {
            return false;
        }
        const tag = (target.tagName || '').toLowerCase();
        return target.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select';
    }

    saveCommentBtn.addEventListener('click', function () {
        const commentText = commentTextarea.value.trim();
        if (!commentText) {
            notify('Please enter a comment.');
            return;
        }
        if (!activeAnnotation) {
            notify('Click on artwork before posting a comment.');
            return;
        }

        const fd = new FormData();
        fd.append('file_id', window.fileId);
        fd.append('comment', commentText);
        fd.append('type', activeAnnotation.type);
        fd.append('x_pos', activeAnnotation.x != null ? activeAnnotation.x : '');
        fd.append('y_pos', activeAnnotation.y != null ? activeAnnotation.y : '');
        fd.append('width', activeAnnotation.w != null ? activeAnnotation.w : '');
        fd.append('height', activeAnnotation.h != null ? activeAnnotation.h : '');
        fd.append('drawing_data', activeAnnotation.data != null ? activeAnnotation.data : '');
        fd.append('user_name', window.clientName || 'Client');

        if (refInput && refInput.files && refInput.files[0]) {
            fd.append('attachment', refInput.files[0]);
        }

        fetch('api/add-comment.php', {
            method: 'POST',
            body: fd
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (data.status !== 'success') {
                notify(data.message || 'Unable to add comment.');
                return;
            }
            resetCommentComposer();
            refreshCommentsWithoutReload().then(function (ok) {
                if (!ok) {
                    reloadWithViewState();
                }
            });
        }).catch(function () {
            notify('Failed to send comment.');
        });
    });

    cancelCommentBtn.addEventListener('click', function () {
        resetCommentComposer();
    });

    if (closeCommentBtn) {
        closeCommentBtn.addEventListener('click', function () {
            resetCommentComposer();
        });
    }

    function normalizeShortcut(event) {
        const code = event.code || '';
        const key = typeof event.key === 'string' ? event.key : '';
        const lowerKey = key.toLowerCase();
        return {
            key: key,
            lowerKey: lowerKey,
            isSpace: key === ' ' || key === 'Spacebar' || code === 'Space',
            toolByCode: {
                KeyV: 'select',
                KeyP: 'point',
                KeyA: 'area',
                KeyW: 'arrow',
                KeyD: 'pen',
                KeyI: 'highlighter',
                KeyH: 'pan'
            }[code] || null
        };
    }

    function handleShortcutKeydown(event) {
        if (event.defaultPrevented) {
            return;
        }

        const normalized = normalizeShortcut(event);
        const key = normalized.key;
        const lowerKey = normalized.lowerKey;
        const typingContext = isTypingContext(event.target);

        if (key === 'Escape' && commentBox.style.display === 'block') {
            resetCommentComposer();
            return;
        }

        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (normalized.isSpace && !typingContext) {
            if (!spacePanActive) {
                spacePanActive = true;
                spacePanPreviousTool = currentTool;
                setActiveTool('pan');
            }
            event.preventDefault();
            return;
        }

        if (typingContext) {
            return;
        }

        const toolShortcutMap = {
            v: 'select',
            p: 'point',
            a: 'area',
            w: 'arrow',
            d: 'pen',
            i: 'highlighter',
            h: 'pan'
        };

        const targetTool = normalized.toolByCode || toolShortcutMap[lowerKey];
        if (targetTool) {
            setActiveTool(targetTool);
            event.preventDefault();
            return;
        }

        if ((key === '+' || key === '=') && panzoom && typeof panzoom.zoomIn === 'function') {
            panzoom.zoomIn();
            event.preventDefault();
            return;
        }

        if (key === '-' && panzoom && typeof panzoom.zoomOut === 'function') {
            panzoom.zoomOut();
            event.preventDefault();
            return;
        }

        if (lowerKey === 'r' && panzoom && typeof panzoom.reset === 'function') {
            panzoom.reset();
            saveViewState();
            event.preventDefault();
        }
    }

    function handleShortcutKeyup(event) {
        if (event.defaultPrevented) {
            return;
        }
        const normalized = normalizeShortcut(event);
        if (normalized.isSpace && spacePanActive) {
            spacePanActive = false;
            setActiveTool(spacePanPreviousTool || 'select');
            event.preventDefault();
        }
    }

    window.addEventListener('keydown', handleShortcutKeydown, true);
    window.addEventListener('keyup', handleShortcutKeyup, true);

    document.querySelectorAll('.pin, .comment-area, .comment-item').forEach(function (node) {
        node.addEventListener('mouseenter', function (event) {
            const source = event.target.closest('[data-id]') || node;
            const id = source.getAttribute('data-id');
            if (!id) return;
            activateLinkedComment(id);
        });
        node.addEventListener('mouseleave', clearLinkedComment);
        node.addEventListener('click', function (event) {
            // Don't open popup when clicking interactive elements inside comment-item
            if (event.target.closest('.reply-input-box') ||
                event.target.closest('.btn-reply-toggle') ||
                event.target.closest('[data-delete-comment]') ||
                event.target.closest('.reply-list') ||
                event.target.closest('button') ||
                event.target.closest('textarea') ||
                event.target.closest('a')) {
                return;
            }
            event.stopPropagation();
            const source = event.target.closest('[data-id]') || node;
            const id = source.getAttribute('data-id');
            if (!id) return;
            openCommentViewPopup(id, source);
        });
    });

    document.querySelectorAll('[data-delete-comment]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const commentId = button.getAttribute('data-delete-comment');
            if (!commentId) {
                return;
            }
            confirmAction('Delete this comment?', 'Delete Comment').then(function (ok) {
                if (!ok) {
                    return;
                }

                const fd = new FormData();
                fd.append('comment_id', commentId);
                fd.append('token', window.projectToken);

                fetch('api/delete-comment.php', {
                    method: 'POST',
                    body: fd
                }).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (data.status !== 'success') {
                        notify(data.message || 'Unable to delete comment.');
                        return;
                    }
                    reloadWithViewState();
                }).catch(function () {
                    notify('Failed to delete comment.');
                });
            });
        });
    });

    window.reloadReviewWithViewState = reloadWithViewState;
    window.reviewNotify = notify;
    window.reviewConfirm = confirmAction;
    window.getReviewZoomScale = function () {
        if (panzoom && typeof panzoom.getScale === 'function') {
            return panzoom.getScale();
        }
        return 1;
    };

    const annotationToolNames = ['point', 'area', 'arrow', 'pen', 'highlighter'];
    window.setReviewReadOnly = function (readOnly) {
        document.querySelectorAll('.tool-btn[data-tool]').forEach(function (btn) {
            const tool = btn.getAttribute('data-tool');
            if (annotationToolNames.includes(tool)) {
                btn.disabled = readOnly;
                btn.style.opacity = readOnly ? '0.35' : '';
                btn.style.pointerEvents = readOnly ? 'none' : '';
            }
        });
        if (readOnly && annotationToolNames.includes(currentTool)) {
            setActiveTool('select');
        }
        if (interactionLayer) {
            interactionLayer.dataset.readonly = readOnly ? '1' : '0';
        }
    };

    window.renderCommentsForVersion = function (comments) {
        document.querySelectorAll('#pins-container .pin:not(.temp-pin), #pins-container .comment-area').forEach(function (el) {
            el.remove();
        });
        markupRecords.length = 0;
        redrawStoredMarkup();

        if (!Array.isArray(comments) || comments.length === 0) return;

        comments.forEach(function (comment, index) {
            let type = comment.type || 'point';
            const xPos = parseFloat(comment.x_pos);
            const yPos = parseFloat(comment.y_pos);

            if (comment.drawing_data) {
                try {
                    const data = JSON.parse(comment.drawing_data);
                    if (data && data.start && data.end) {
                        type = 'arrow';
                        markupRecords.push({ id: Number(comment.id), type: 'arrow', color: data.color || '#f97316', start: data.start, end: data.end, text: data.text || comment.comment || '' });
                    } else if (data && Array.isArray(data.path) && data.path.length > 1) {
                        const normalizedType = data.tool === 'highlighter' || data.tool === 'pen' ? data.tool : type;
                        type = normalizedType;
                        markupRecords.push({ id: Number(comment.id), type: normalizedType, color: data.color || '#0f766e', path: data.path });
                    }
                } catch (e) { /* ignore */ }
            }

            if ((type === 'area') && comment.width != null) {
                const area = document.createElement('div');
                area.className = 'comment-area';
                area.dataset.id = comment.id;
                area.dataset.type = 'area';
                area.style.left   = parseFloat(comment.x_pos) + '%';
                area.style.top    = parseFloat(comment.y_pos) + '%';
                area.style.width  = parseFloat(comment.width)  + '%';
                area.style.height = parseFloat(comment.height) + '%';
                const pinSpan = document.createElement('span');
                pinSpan.className = 'pin';
                pinSpan.dataset.type = 'area';
                pinSpan.style.left = '0';
                pinSpan.style.top  = '0';
                pinSpan.style.transform = 'translate(-50%,-50%)';
                pinSpan.textContent = index + 1;
                area.appendChild(pinSpan);
                pinsContainer.appendChild(area);
            } else if (!isNaN(xPos) && !isNaN(yPos) && ['point','pen','highlighter'].includes(type)) {
                const pin = document.createElement('div');
                pin.className = 'pin';
                pin.dataset.id   = comment.id;
                pin.dataset.type = type;
                pin.style.left = xPos + '%';
                pin.style.top  = yPos + '%';
                pin.textContent = index + 1;
                pinsContainer.appendChild(pin);
            }
        });

        redrawStoredMarkup();

        document.querySelectorAll('#pins-container .pin:not(.temp-pin), #pins-container .comment-area').forEach(function (node) {
            node.addEventListener('mouseenter', function (event) {
                const source = event.target.closest('[data-id]') || node;
                const id = source.getAttribute('data-id');
                if (id) { activateLinkedComment(id); }
            });
            node.addEventListener('mouseleave', clearLinkedComment);
            node.addEventListener('click', function (event) {
                event.stopPropagation();
                const source = event.target.closest('[data-id]') || node;
                const id = source.getAttribute('data-id');
                if (id) { openCommentViewPopup(id, source); }
            });
        });
    };

    window.attachDeleteHandlers = function () {
        document.querySelectorAll('[data-delete-comment]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                const commentId = button.getAttribute('data-delete-comment');
                if (!commentId) return;
                confirmAction('Delete this comment?', 'Delete Comment').then(function (ok) {
                    if (!ok) return;
                    const fd = new FormData();
                    fd.append('comment_id', commentId);
                    fd.append('token', window.projectToken);
                    fetch('api/delete-comment.php', { method: 'POST', body: fd })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.status !== 'success') { notify(data.message || 'Unable to delete comment.'); return; }
                            reloadWithViewState();
                        })
                        .catch(function () { notify('Failed to delete comment.'); });
                });
            });
        });

        document.querySelectorAll('.pin:not(.temp-pin), .comment-area, .comment-item').forEach(function (node) {
            node.addEventListener('mouseenter', function (event) {
                const source = event.target.closest('[data-id]') || node;
                const id = source.getAttribute('data-id');
                if (id) { activateLinkedComment(id); highlightComment(id, false); }
            });
            node.addEventListener('mouseleave', clearLinkedComment);
        });
    };

    // Render existing annotations (including SVG markup for pen/arrow/highlighter)
    if (window.initialComments && Array.isArray(window.initialComments)) {
        window.renderCommentsForVersion(window.initialComments);
    } else {
        redrawStoredMarkup();
    }

    let shouldRestoreOnce = false;
    try {
        shouldRestoreOnce = sessionStorage.getItem(viewRestoreOnceKey) === '1';
        if (shouldRestoreOnce) {
            sessionStorage.removeItem(viewRestoreOnceKey);
        }
    } catch (e) {
        shouldRestoreOnce = false;
    }

    if (shouldRestoreOnce) {
        if (!restoreViewState()) {
            fitArtworkToViewer();
        }
    } else {
        fitArtworkToViewer();
    }

    window.addEventListener('resize', function () {
        resizeDrawingCanvas();
        redrawStoredMarkup();
    });
});

function highlightComment(id) {
    const shouldScroll = arguments.length > 1 ? arguments[1] : true;
    document.querySelectorAll('.comment-item').forEach(function (item) {
        item.classList.remove('active');
    });
    const selected = document.querySelector('.comment-item[data-id="' + id + '"]');
    if (selected) {
        selected.classList.add('active');
        if (shouldScroll) {
            selected.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

function toggleReplyForm(commentId) {
    const box = document.getElementById('reply-box-' + commentId);
    if (!box) {
        return;
    }
    box.style.display = box.style.display === 'block' ? 'none' : 'block';
}

function saveReply(parentId) {
    const box = document.getElementById('reply-box-' + parentId);
    if (!box) {
        return;
    }
    const textarea = box.querySelector('textarea');
    const message = textarea ? textarea.value.trim() : '';
    if (!message) {
        return;
    }

    const fd = new FormData();
    fd.append('file_id', window.fileId);
    fd.append('comment', message);
    fd.append('parent_id', parentId);
    fd.append('user_name', window.clientName || 'Client');

    fetch('api/add-comment.php', {
        method: 'POST',
        body: fd
    }).then(function (res) {
        return res.json();
    }).then(function (data) {
        if (data.status !== 'success') {
            if (typeof window.reviewNotify === 'function') {
                window.reviewNotify(data.message || 'Unable to send reply.');
            }
            return;
        }
        if (typeof window.reloadReviewWithViewState === 'function') {
            window.reloadReviewWithViewState();
            return;
        }
        window.location.reload();
    }).catch(function () {
        if (typeof window.reviewNotify === 'function') {
            window.reviewNotify('Failed to send reply.');
        }
    });
}

function approveArtwork() {
    const proceed = typeof window.reviewConfirm === 'function'
        ? window.reviewConfirm('Approve this artwork?', 'Approve Artwork')
        : Promise.resolve(false);
    proceed.then(function (ok) {
        if (ok) {
            updateStatus('approved');
        }
    });
}

function requestChanges() {
    const proceed = typeof window.reviewConfirm === 'function'
        ? window.reviewConfirm('Request changes for this artwork?', 'Request Changes')
        : Promise.resolve(false);
    proceed.then(function (ok) {
        if (ok) {
            updateStatus('changes');
        }
    });
}

function notifyStatusError(message) {
    if (typeof window.reviewNotify === 'function') {
        window.reviewNotify(message);
    }
}

function updateStatus(status) {
    const fd = new FormData();
    fd.append('token', window.projectToken);
    fd.append('status', status);

    fetch('api/update-status.php', {
        method: 'POST',
        body: fd
    }).then(function (res) {
        return res.json();
    }).then(function (data) {
        if (data.status !== 'success') {
            notifyStatusError(data.message || 'Unable to update status.');
            return;
        }
        if (typeof window.reloadReviewWithViewState === 'function') {
            window.reloadReviewWithViewState();
            return;
        }
        window.location.reload();
    }).catch(function () {
        notifyStatusError('Failed to update status.');
    });
}
