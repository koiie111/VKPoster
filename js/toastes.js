// Функция для создания и отображения уведомления
function createAndShowToast(message, toastClass) {
    const toastContainer = document.getElementById('toastContainer');

    const toast = document.createElement('div');
    toast.classList.add('toast');
    toast.classList.add(toastClass);
    toast.classList.add('border-0');
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    const toastInnerWrapper = document.createElement('div');
    toastInnerWrapper.classList.add('d-flex');

    const toastBody = document.createElement('div');
    toastBody.classList.add('toast-body');
    toastBody.textContent = message;

    const closeButton = document.createElement('button');
    closeButton.setAttribute('type', 'button');
    closeButton.classList.add('btn-close');
    closeButton.classList.add('btn-close-white');
    closeButton.classList.add('me-2');
    closeButton.classList.add('m-auto');
    closeButton.setAttribute('data-bs-dismiss', 'toast');
    closeButton.setAttribute('aria-label', 'Закрыть');

    toastInnerWrapper.appendChild(toastBody);
    toastInnerWrapper.appendChild(closeButton);

    toast.appendChild(toastInnerWrapper);

    // Вставляем новое уведомление перед первым уведомлением
    if (toastContainer.firstChild) {
        toastContainer.insertBefore(toast, toastContainer.firstChild);
    } else {
        toastContainer.appendChild(toast);
    }

    const bootstrapToast = new bootstrap.Toast(toast);
    bootstrapToast.show();
}

// Пример использования для разных типов уведомлений
function showShortErrorToast(message) {
    createAndShowToast(message, 'text-bg-danger');
}

function showShortPrimaryToast(message) {
    createAndShowToast(message, 'text-bg-primary');
}

function showShortSuccessToast(message) {
    createAndShowToast(message, 'text-bg-success');
}