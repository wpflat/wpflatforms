import { FileText } from 'lucide-vue-next';
import { sdk } from './core';
import { canForm } from './util';
import FormsView from './views/FormsView.vue';
import ClientFormsTab from './slots/ClientFormsTab.vue';

/** WPFlat Forms registers its one screen: the forms and the answers people sent. */
const M = 'form';
sdk.registerIcon( 'form', FileText );
sdk.registerRoute( { path: '/forms', module: M, component: FormsView } );
sdk.registerSlot( 'client.tabs', { id: 'form', module: M, order: 60, label: 'Forms', component: ClientFormsTab, when: canForm } );
