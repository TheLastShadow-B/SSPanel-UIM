{* 与 t-dropdown is-open 配合；Alpine 在过渡结束后清理临时类。 *}
x-transition:enter="cafe-dropdown-enter"
x-transition:enter-start="is-closing"
x-transition:enter-end=""
x-transition:leave="cafe-dropdown-leave"
x-transition:leave-start=""
x-transition:leave-end="is-closing"
