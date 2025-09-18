document.addEventListener('DOMContentLoaded', function () {
    const editBtn = document.getElementById('tfg-edit-profile');
    const saveBtn = document.getElementById('tfg-save-profile');
    const displayDiv = document.getElementById('tfg-profile-display');
    const editDiv = document.getElementById('tfg-profile-edit');

    if (editBtn && displayDiv && editDiv) {
        editBtn.addEventListener('click', () => {
            displayDiv.style.display = 'none';
            editDiv.style.display = 'block';
        });
    }

    if (saveBtn && displayDiv && editDiv) {
        saveBtn.addEventListener('click', () => {
            // Optional: You could add a form submission handler here
            editDiv.style.display = 'none';
            displayDiv.style.display = 'block';
        });
    }
});

