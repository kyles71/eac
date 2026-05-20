window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.type !== 'filament-theme-builder:preview') {
        return
    }

    let style = document.querySelector('style[data-filament-theme-builder-preview]')

    if (!style) {
        style = document.createElement('style')
        style.setAttribute('data-filament-theme-builder-preview', '')
        document.head.appendChild(style)
    }

    style.textContent = event.data.css || ''
    document.documentElement.classList.toggle('dark', event.data.scheme === 'dark')
    document.documentElement.classList.add('fi-theme-builder-preview-active')
})
