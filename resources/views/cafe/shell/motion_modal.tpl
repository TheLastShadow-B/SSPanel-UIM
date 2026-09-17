{* 与 t-modal is-open 配合，父遮罩等候子卡片完成退出。 *}
x-transition:enter="cafe-modal-enter"
x-transition:enter-start="is-closing"
x-transition:enter-end=""
x-transition:leave="cafe-modal-leave"
x-transition:leave-start=""
x-transition:leave-end="is-closing"
