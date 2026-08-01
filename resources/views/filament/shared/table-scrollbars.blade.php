<script>
    (() => {
        if (window.eacTableScrollbarInitialized) {
            return
        }

        window.eacTableScrollbarInitialized = true

        const rail = document.createElement('div')
        const thumb = document.createElement('div')
        let activeTable = null
        let animationFrame = null
        let dragPointerId = null
        let dragStartX = 0
        let dragStartScrollLeft = 0

        rail.className = 'eac-table-scrollbar'
        rail.setAttribute('aria-label', 'Horizontal table scroll')
        rail.setAttribute('aria-orientation', 'horizontal')
        rail.setAttribute('role', 'scrollbar')
        rail.tabIndex = 0
        thumb.className = 'eac-table-scrollbar-thumb'
        rail.append(thumb)
        document.body.append(rail)

        const scrollMetrics = () => {
            if (! activeTable) {
                return null
            }

            const maximumScroll = activeTable.scrollWidth - activeTable.clientWidth
            const thumbWidth = Math.max(
                48,
                rail.clientWidth * (activeTable.clientWidth / activeTable.scrollWidth),
            )
            const thumbTravel = Math.max(0, rail.clientWidth - thumbWidth)

            return {
                maximumScroll,
                thumbTravel,
                thumbWidth,
            }
        }

        const updateThumb = () => {
            const metrics = scrollMetrics()

            if (! metrics) {
                return
            }

            const progress = metrics.maximumScroll > 0
                ? activeTable.scrollLeft / metrics.maximumScroll
                : 0

            thumb.style.width = `${metrics.thumbWidth}px`
            thumb.style.transform = `translateX(${progress * metrics.thumbTravel}px)`
            rail.setAttribute('aria-valuemax', `${metrics.maximumScroll}`)
            rail.setAttribute('aria-valuenow', `${activeTable.scrollLeft}`)
            rail.setAttribute('aria-valuemin', '0')
        }

        const tableCandidates = () => Array.from(
            document.querySelectorAll([
                '.fi-panel-admin .fi-ta-content-ctn',
                '.fi-panel-user .fi-ta-content-ctn',
            ].join(', ')),
        ).filter((table) => {
            const bounds = table.getBoundingClientRect()

            return table.scrollWidth > table.clientWidth + 1
                && bounds.top < window.innerHeight
                && bounds.bottom > window.innerHeight
                && bounds.width > 0
        })

        const update = () => {
            animationFrame = null
            const candidates = tableCandidates()
            const table = candidates.at(-1) ?? null

            if (! table) {
                activeTable = null
                rail.hidden = true

                return
            }

            activeTable = table

            const bounds = table.getBoundingClientRect()
            const left = Math.max(0, bounds.left)
            const right = Math.min(window.innerWidth, bounds.right)

            rail.style.insetInlineStart = `${left}px`
            rail.style.width = `${Math.max(0, right - left)}px`
            rail.hidden = false
            updateThumb()
        }

        const scheduleUpdate = () => {
            if (animationFrame !== null) {
                return
            }

            animationFrame = window.requestAnimationFrame(update)
        }

        rail.addEventListener('pointerdown', (event) => {
            const metrics = scrollMetrics()

            if (! activeTable || ! metrics) {
                return
            }

            if (event.target !== thumb && metrics.thumbTravel > 0) {
                const bounds = rail.getBoundingClientRect()
                const position = Math.min(
                    metrics.thumbTravel,
                    Math.max(0, event.clientX - bounds.left - (metrics.thumbWidth / 2)),
                )

                activeTable.scrollLeft = (position / metrics.thumbTravel) * metrics.maximumScroll
                updateThumb()
            }

            dragPointerId = event.pointerId
            dragStartX = event.clientX
            dragStartScrollLeft = activeTable.scrollLeft
            rail.setPointerCapture(event.pointerId)
            rail.classList.add('eac-table-scrollbar-dragging')
        })

        rail.addEventListener('pointermove', (event) => {
            const metrics = scrollMetrics()

            if (
                ! activeTable
                || ! metrics
                || event.pointerId !== dragPointerId
                || metrics.thumbTravel <= 0
            ) {
                return
            }

            activeTable.scrollLeft = dragStartScrollLeft
                + ((event.clientX - dragStartX) / metrics.thumbTravel) * metrics.maximumScroll
            updateThumb()
        })

        const finishDragging = (event) => {
            if (event.pointerId !== dragPointerId) {
                return
            }

            dragPointerId = null
            rail.classList.remove('eac-table-scrollbar-dragging')
        }

        rail.addEventListener('pointercancel', finishDragging)
        rail.addEventListener('pointerup', finishDragging)

        rail.addEventListener('wheel', (event) => {
            if (! activeTable) {
                return
            }

            event.preventDefault()
            activeTable.scrollLeft += event.deltaX || event.deltaY
            updateThumb()
        }, { passive: false })

        rail.addEventListener('keydown', (event) => {
            if (! activeTable) {
                return
            }

            const distances = {
                ArrowLeft: -40,
                ArrowRight: 40,
                PageUp: -activeTable.clientWidth,
                PageDown: activeTable.clientWidth,
                Home: -activeTable.scrollWidth,
                End: activeTable.scrollWidth,
            }

            if (! Object.hasOwn(distances, event.key)) {
                return
            }

            event.preventDefault()
            activeTable.scrollLeft += distances[event.key]
            updateThumb()
        })

        document.addEventListener('scroll', scheduleUpdate, { capture: true, passive: true })
        window.addEventListener('resize', scheduleUpdate, { passive: true })
        document.addEventListener('livewire:navigated', scheduleUpdate)

        new MutationObserver(scheduleUpdate).observe(document.body, {
            childList: true,
            subtree: true,
        })

        scheduleUpdate()
    })()
</script>
