import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// WPFlatForms's app bundle runs inside WPFlatCore's page: Vue is not bundled again, it is WPFlatCore's window.Vue.
export default defineConfig( {
	root: 'ui',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"' },
	build: {
		outDir: '../build',
		emptyOutDir: true,
		cssCodeSplit: false,
		lib: { entry: 'main.js', name: 'WPFlatForms', formats: [ 'iife' ], fileName: () => 'wpflatforms.js', cssFileName: 'wpflatforms' },
		rollupOptions: { external: [ 'vue' ], output: { globals: { vue: 'Vue' } } },
	},
} );
