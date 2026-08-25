import '../css/booking.css';
import axios from 'axios';
import { createApp } from 'vue';
import ManageIsland from './Pages/Public/ManageIsland.vue';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

const props = JSON.parse(document.getElementById('manage-props')?.textContent ?? '{}');
createApp(ManageIsland, props).mount('#manage-app');
