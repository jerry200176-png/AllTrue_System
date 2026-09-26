const [{createApp,h},styles,module]=await Promise.all([import('vue'),import('../../../src/styles.css'),import('../../../src/pages/DirectorAccountsPage.vue')]);
void styles;createApp({setup(){return()=>h(module.default,{token:'synthetic-test-token'});}}).mount('#app');
document.documentElement.dataset.pilotReady='1';
