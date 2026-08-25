import '../css/booking.css';
import axios from 'axios';
import { createApp } from 'vue';
import BookingIsland from './Pages/Public/BookingIsland.vue';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

const raw = document.getElementById('booking-props')?.textContent ?? '{}';
const props = JSON.parse(raw);

createApp(BookingIsland, props).mount('#booking-app');
