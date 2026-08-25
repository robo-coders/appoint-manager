import '../css/booking.css';
import axios from 'axios';
import { createApp } from 'vue';
import OfferIsland from './Pages/Public/OfferIsland.vue';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

const props = JSON.parse(document.getElementById('offer-props')?.textContent ?? '{}');
createApp(OfferIsland, props).mount('#offer-app');
