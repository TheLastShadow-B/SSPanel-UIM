{* 遮罩仅淡入淡出；卡片缩放由 motion_modal.tpl 负责。 *}
x-transition:enter="cafe-backdrop-enter"
x-transition:enter-start="opacity-0"
x-transition:enter-end="opacity-100"
x-transition:leave="cafe-backdrop-leave"
x-transition:leave-start="opacity-100"
x-transition:leave-end="opacity-0"
