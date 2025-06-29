// Global variable to store the main jQuery elements for Kanban board for easier access
var kanbanGlobals = {
    apiUrl: 'php/tasks_api.php',
    boardsApiUrl: 'php/boards_api.php', // Added for fetching board members
    columns: {
        'todo': 'To Do',
        'inprogress': 'In Progress',
        'done': 'Done'
    },
    kanbanBoardContainerSelector: '.kanban-board-columns-container',
    taskModalSelector: '#taskModal',
};

// Function to be called when user is authenticated and board context is ready
function initializeKanban() {
    console.log("Initializing Kanban board (tasks)...");
    createAddTaskButton(); 
    loadTasks(); 
    $('#imageUploadGroup').show();
    $('#assignUserGroup').show(); // Show assignee dropdown
}

// Function to clear Kanban board content
function clearKanbanBoardApp() { 
    console.log("Clearing Kanban board (tasks)...");
    $(kanbanGlobals.kanbanBoardContainerSelector).empty();
    $('#addTaskButtonContainer').remove(); 
    $('#imageUploadGroup').hide();
    $('#assignUserGroup').hide();
}

const apiUrl = kanbanGlobals.apiUrl; 
const columns = kanbanGlobals.columns;

function createTaskCard(task) {
    let imageHtml = '';
    if (task.image_path) {
        const imageUrl = (task.image_path.startsWith('http') || task.image_path.startsWith('/')) ? task.image_path : ( (typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '') + '/' + task.image_path);
        imageHtml = `<img src="${escapeHtml(imageUrl)}" alt="Task Image" class="img-thumbnail mt-2" style="max-width: 100%; height: auto;">`;
    }
    let assigneeHtml = '';
    if (task.assigned_to_user_id && task.assignee_username) {
        assigneeHtml = `<p class="card-text mt-2 mb-0"><small class="text-muted">Assigned to: <strong>${escapeHtml(task.assignee_username)}</strong></small></p>`;
    } else if (task.assigned_to_user_id) {
         assigneeHtml = `<p class="card-text mt-2 mb-0"><small class="text-muted">Assigned to: User ID ${escapeHtml(task.assigned_to_user_id)}</small></p>`;
    }

    return `
        <div class="card task-card" data-id="${task.id}" draggable="true">
            <div class="card-body">
                <h5 class="card-title">${escapeHtml(task.title)}</h5>
                <p class="card-text">${escapeHtml(task.description)}</p>
                ${imageHtml}
                ${assigneeHtml}
                <div class="mt-2"> 
                    <button class="btn btn-sm btn-danger deleteTask" data-id="${task.id}" style="float: right;">Delete</button>
                    <button class="btn btn-sm btn-info editTask" data-id="${task.id}" style="float: right; margin-right: 5px;">Edit</button>
                </div>
            </div>
        </div>
    `;
}

function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    return unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// handleApiError is now globally defined in auth.js, so script.js can use it.
// If it were specific to script.js, it would be defined here.

function loadTasks() {
    console.log("loadTasks called in script.js");
    const activeBoardId = typeof getActiveBoardId === 'function' ? getActiveBoardId() : null;
    if (!activeBoardId) {
        console.log("No active board to load tasks for.");
        $(kanbanGlobals.kanbanBoardContainerSelector).html('<p class="text-center text-muted">Please select a board to view tasks.</p>');
        $('#noBoardSelectedView').show(); // Make sure this is visible
        return;
    }
    $('#noBoardSelectedView').hide();
    $(kanbanGlobals.kanbanBoardContainerSelector).empty().html('<p class="text-center">Loading tasks...</p>');

    let columnHtml = '<div class="row w-100 mx-0">'; 
    Object.keys(columns).forEach(key => {
        columnHtml += `
            <div class="col-md-4">
                <div class="kanban-column" id="column-${key}" data-status="${key}">
                    <h3 class="column-title">${columns[key]}</h3>
                    <div class="tasks-container ui-sortable"></div>
                </div>
            </div>`;
    });
    columnHtml += '</div>';
    $(kanbanGlobals.kanbanBoardContainerSelector).html(columnHtml);

    const $taskStatus = $('#taskStatus'); $taskStatus.empty();
    Object.keys(columns).forEach(key => { $taskStatus.append(`<option value="${key}">${columns[key]}</option>`); });

    $.ajax({
        url: apiUrl, method: 'GET', data: { action: 'get_tasks', board_id: activeBoardId }, dataType: 'json',
        success: function(response) {
            $('.tasks-container').empty(); // Clear loading message from individual columns
            if (response.success && response.tasks) {
                if(response.tasks.length === 0) {
                     $(kanbanGlobals.kanbanBoardContainerSelector).append('<p class="text-center col-12 text-muted mt-3">No tasks on this board yet. Add one!</p>');
                }
                response.tasks.forEach(task => {
                    const taskCardHtml = createTaskCard(task);
                    const columnTarget = $(`#column-${task.status} .tasks-container`);
                    if(columnTarget.length) columnTarget.append(taskCardHtml);
                    else console.error(`Column for status ${task.status} not found.`);
                });
                makeTasksSortableAndDroppable();
            } else {
                if (response.redirectToLogin) handleGlobalApiError({status: 401, responseJSON: response}, 'Failed to load tasks');
                else {
                    console.error('Failed to load tasks:', response.message);
                    $(kanbanGlobals.kanbanBoardContainerSelector).html(`<p class="text-danger text-center">Error: ${response.message || 'Could not load tasks.'}</p>`);
                }
            }
        },
        error: function(xhr) {
            handleGlobalApiError(xhr, 'Error loading tasks.');
            $(kanbanGlobals.kanbanBoardContainerSelector).html('<p class="text-danger text-center">Server error loading tasks.</p>');
        }
    });
}

function makeTasksSortableAndDroppable() {
    $(".tasks-container").sortable({
        connectWith: ".tasks-container", placeholder: "ui-sortable-placeholder", items: "> .task-card",
        cursor: "grabbing", tolerance: "pointer",
        start: function(event, ui) { ui.placeholder.height(ui.item.outerHeight()); ui.placeholder.width(ui.item.outerWidth()); },
        stop: function(event, ui) { updateTaskOrderOnBackend(); },
        over: function() { $(this).closest('.kanban-column').addClass('column-drag-over'); },
        out: function() { $(this).closest('.kanban-column').removeClass('column-drag-over'); }
    }).disableSelection(); 
}

function updateTaskOrderOnBackend() {
    let tasksToUpdate = [];
    $('.kanban-column').each(function() {
        const statusKey = $(this).data('status');
        $(this).find('.task-card').each(function(index) {
            tasksToUpdate.push({ id: $(this).data('id'), status: statusKey, order: index });
        });
    });
    const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
    if (!token && tasksToUpdate.length > 0) { alert('Security token missing for reorder. Refresh.'); loadTasks(); return; }
    if (tasksToUpdate.length === 0) return;
    $.ajax({
        url: apiUrl, method: 'POST',
        data: { action: 'update_task_order_and_status', tasks: JSON.stringify(tasksToUpdate), csrf_token: token },
        dataType: 'json',
        success: function(response) { if (!response.success) { alert('Error updating order: ' + response.message); loadTasks(); }},
        error: function(xhr) { handleGlobalApiError(xhr, 'Server error updating order.'); loadTasks(); }
    });
}

function createAddTaskButton() {
    if ($('#addTaskButtonContainer').length === 0 && getActiveBoardId()) {
        const btnHtml = `<div class="container mb-3" id="addTaskButtonContainer" style="text-align: right;"><button type="button" class="btn btn-success" data-toggle="modal" data-target="${kanbanGlobals.taskModalSelector}" id="openAddTaskModal">Add New Task</button></div>`;
        $(kanbanGlobals.kanbanBoardContainerSelector).before(btnHtml);
    } else if (!getActiveBoardId()) {
        $('#addTaskButtonContainer').remove();
    }
}

$(document).on('click', '#openAddTaskModal', function() {
    $('#taskForm')[0].reset(); $('#taskId').val(''); $('#taskModalLabel').text('Add New Task');
    $('#taskImagePreview').hide().attr('src', '#').data('image-removed', false);
    $('#removeTaskImage').hide(); $('#taskImage').val('');
    $('#taskAssignee').val(''); 
    populateAssigneeDropdown(); 
});

$('#saveTask').on('click', function() {
    const taskId = $('#taskId').val(); const taskTitle = $('#taskTitle').val().trim();
    const taskDescription = $('#taskDescription').val().trim(); const taskStatus = $('#taskStatus').val();
    const taskImageFile = $('#taskImage')[0].files[0]; const assignedToUserId = $('#taskAssignee').val();
    if (!taskTitle) { alert('Task title is required.'); return; }
    let formData = new FormData();
    formData.append('action', taskId ? 'update_task' : 'add_task');
    if (taskId) formData.append('id', taskId);
    formData.append('title', taskTitle); formData.append('description', taskDescription); formData.append('status', taskStatus);
    if (assignedToUserId) formData.append('assigned_to_user_id', assignedToUserId);
    if (taskImageFile) formData.append('task_image', taskImageFile);
    else if ($('#taskImagePreview').data('image-removed') === true) formData.append('remove_image', 'true');
    const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
    if (!token) { alert('Security token missing. Refresh.'); return; }
    formData.append('csrf_token', token);
    // Add board_id if it's a super_admin potentially creating task for a specific board not in their session
    const currentUser = typeof getCurrentUserData === 'function' ? getCurrentUserData() : null;
    const activeBoard = typeof getActiveBoardId === 'function' ? getActiveBoardId() : null;
    if (currentUser && currentUser.role === 'super_admin' && activeBoard) { // Ensure activeBoard is context for SA
        formData.append('board_id', activeBoard);
    }

    $.ajax({
        url: apiUrl, method: 'POST', data: formData, dataType: 'json', contentType: false, processData: false,
        success: function(response) {
            if (response.success) { $(kanbanGlobals.taskModalSelector).modal('hide'); loadTasks(); }
            else { showGlobalMessage('Error saving task: ' + (response.message || 'Unknown error'), false); }
        },
        error: function(xhr) { handleGlobalApiError(xhr, 'Server error saving task.'); }
    });
});

$(document).on('click', '.editTask', function(e) {
    e.stopPropagation(); const taskId = $(this).data('id');
    populateAssigneeDropdown().then(() => { // Ensure dropdown is populated before setting value
        $.ajax({
            url: apiUrl, method: 'GET', data: { action: 'get_task', id: taskId }, dataType: 'json',
            success: function(response) {
                if (response.success && response.task) {
                    const task = response.task;
                    $('#taskId').val(task.id); $('#taskTitle').val(task.title);
                    $('#taskDescription').val(task.description); $('#taskStatus').val(task.status);
                    $('#taskModalLabel').text('Edit Task');
                    $('#taskAssignee').val(task.assigned_to_user_id || '');
                    $('#taskImagePreview').data('image-removed', false);
                    if (task.image_path) {
                        const imageUrl = (task.image_path.startsWith('http') || task.image_path.startsWith('/')) ? task.image_path : ( (typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '') + '/' + task.image_path);
                        $('#taskImagePreview').attr('src', escapeHtml(imageUrl) + '?' + new Date().getTime()).show();
                        $('#removeTaskImage').show();
                    } else { $('#taskImagePreview').hide().attr('src', '#'); $('#removeTaskImage').hide(); }
                    $('#taskImage').val(''); 
                    $(kanbanGlobals.taskModalSelector).modal('show');
                } else { showGlobalMessage('Error fetching task details: ' + (response.message || 'Task not found.'), false); }
            },
            error: function(xhr) { handleGlobalApiError(xhr, 'Server error fetching task details.'); }
        });
    });
});
    
$('#removeTaskImage').on('click', function() {
    $('#taskImage').val(''); $('#taskImagePreview').hide().attr('src', '#');
    $(this).hide(); $('#taskImagePreview').data('image-removed', true); 
});

$('#taskImage').on('change', function(event) {
    const reader = new FileReader();
    reader.onload = function() {
        $('#taskImagePreview').attr('src', reader.result).show();
        $('#removeTaskImage').show(); $('#taskImagePreview').data('image-removed', false); 
    }
    if (event.target.files[0]) reader.readAsDataURL(event.target.files[0]);
    else { $('#taskImagePreview').hide().attr('src', '#'); $('#removeTaskImage').hide(); }
});

function populateAssigneeDropdown() {
    const $assigneeSelect = $('#taskAssignee');
    const boardId = typeof getActiveBoardId === 'function' ? getActiveBoardId() : null;
    if (!boardId) {
        $assigneeSelect.html('<option value="">-- Unassigned --</option>');
        return $.Deferred().resolve().promise();
    }
    return $.ajax({
        url: kanbanGlobals.boardsApiUrl, method: 'GET',
        data: { action: 'list_board_members', board_id: boardId }, dataType: 'json',
        success: function(response) {
            $assigneeSelect.empty().append('<option value="">-- Unassigned --</option>');
            if (response.success && response.members) {
                response.members.forEach(member => {
                    $assigneeSelect.append(`<option value="${member.user_id}">${escapeHtml(member.username)}</option>`);
                });
            } else { console.error("Error populating assignees:", response.message); }
        },
        error: function(xhr) {
            console.error("Server error fetching board members for assignee dropdown.");
            handleGlobalApiError(xhr, "Error fetching assignees.");
            $assigneeSelect.html('<option value="">-- Unassigned --</option><option value="" disabled>Error members</option>');
        }
    });
}

$(document).on('click', '.deleteTask', function(e) {
    e.stopPropagation(); const taskId = $(this).data('id');
    const token = typeof getCsrfToken === 'function' ? getCsrfToken() : null;
    if (!token) { alert('Security token missing. Refresh.'); return; }
    if (confirm('Are you sure you want to delete this task?')) {
        $.ajax({
            url: apiUrl, method: 'POST',
            data: { action: 'delete_task', id: taskId, csrf_token: token }, dataType: 'json',
            success: function(response) {
                if (response.success) loadTasks(); 
                else showGlobalMessage('Error deleting task: ' + (response.message || 'Unknown error'), false);
            },
            error: function(xhr) { handleGlobalApiError(xhr, 'Server error deleting task.');}
        });
    }
});
```
This ensures `js/script.js` is fully updated for task assignments and other accumulated features.

With the backend changes in `php/tasks_api.php` (already applied via overwrite), the `ALTER TABLE` SQL provided, and these frontend updates to `index.php` and `js/script.js`, Step 8 "Task Assignment" is now complete.

```
