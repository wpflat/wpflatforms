<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, errorMessage, ElMessage, PageHeader, SenderPicker, store } from '../core';

/** Forms: each form's public link, who is told about answers, and every answer people have sent. */
const forms = ref( [] );
const loading = ref( true );
const current = ref( null );
const subs = ref( [] );
const subsLoading = ref( false );
const detail = ref( null );
const detailOpen = ref( false );
const tab = ref( '0' );
const notify = ref( '' );
const senderEmail = ref( '' ); // '' = the business sender
const saving = ref( false );
const clients = ref( [] );
const clientBusy = ref( false );
const findClients = async ( q ) => {
	clientBusy.value = true;
	try {
		const r = await api.list( 'clients', { search: q || '', per_page: 20 } );
		clients.value = Array.isArray( r ) ? r : ( r.items || r.data || [] );
	} catch ( e ) {
		clients.value = [];
	} finally {
		clientBusy.value = false;
	}
};
const fileUrl = ( id ) => ( store.homeUrl || '/' ) + '?bd_formfile=' + id;
const filesFor = ( entity, field ) => ( detail.value ? detail.value.files.filter( ( f ) => f.field === field && f.entity === entity ) : [] );
const sizeText = ( n ) => ( n >= 1048576 ? ( n / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( n / 1024 ) ) + ' KB' );

const load = async () => {
	loading.value = true;
	try {
		forms.value = await api.getPath( 'forms' );
		if ( current.value ) current.value = forms.value.find( ( f ) => f.id === current.value.id ) || null;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
const open = async ( f ) => {
	current.value = f;
	notify.value = f.notify_email || '';
	senderEmail.value = f.sender_email || '';
	findClients( '' );
	subsLoading.value = true;
	try {
		subs.value = await api.getPath( `forms/${ f.id }/submissions` );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		subsLoading.value = false;
	}
};
const saveForm = async ( patch ) => {
	saving.value = true;
	try {
		await api.post( `forms/${ current.value.id }`, patch );
		ElMessage.success( 'Saved' );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};
const copy = async ( text ) => {
	try {
		await navigator.clipboard.writeText( text );
		ElMessage.success( 'Copied' );
	} catch ( e ) {
		window.prompt( 'Copy this:', text );
	}
};
const show = async ( row ) => {
	try {
		detail.value = await api.getPath( `form-submissions/${ row.id }` );
		tab.value = '0';
		detailOpen.value = true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const remove = async ( row ) => {
	if ( ! window.confirm( `Delete the answers from ${ row.name || row.email || 'this person' }? This cannot be undone.` ) ) return;
	try {
		await api.del( `form-submissions/${ row.id }` );
		subs.value = subs.value.filter( ( s ) => s.id !== row.id );
		detailOpen.value = false;
		load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const download = ( name, text, type ) => {
	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( new Blob( [ text ], { type } ) );
	a.download = name;
	a.click();
	setTimeout( () => URL.revokeObjectURL( a.href ), 2000 );
};
const exportCsv = async () => {
	try {
		const r = await api.getPath( `forms/${ current.value.id }/export` );
		download( r.filename, r.csv, 'text/csv;charset=utf-8' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const addDemo = async () => {
	try {
		const r = await api.post( `forms/${ current.value.id }/demo`, {} );
		ElMessage.success( 'Sample answers added' );
		await open( current.value );
		await load();
		await show( { id: r.id } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const openPdf = () => window.open( detail.value.pdf_url, '_blank', 'noopener' );
const downloadMd = () => download( 'answers-' + detail.value.submission.id + '.md', detail.value.markdown, 'text/markdown;charset=utf-8' );

/* Reading one submission: the form's own sections, in order; repeating sections once per company. */
const data = computed( () => ( detail.value ? detail.value.submission.data : { count: 0, general: {}, entities: [] } ) );
const sections = computed( () => ( detail.value ? detail.value.definition.sections : [] ) );
const group = computed( () => ( detail.value && detail.value.definition.group ) || {} );
const label = ( i ) => {
	const r = ( data.value.general[ group.value.table ] || [] )[ i ];
	const n = r && r.name ? String( r.name ).trim() : '';
	return n || ( i === 0 ? group.value.parent || 'Main company' : ( group.value.sub || 'Subsidiary' ) + ' ' + i );
};
const show_value = ( v ) => ( Array.isArray( v ) ? v.join( ', ' ) : String( v == null ? '' : v ) );
const tabs = computed( () => Array.from( { length: ( data.value.count || 0 ) + 1 }, ( _, i ) => i ) );
const general = computed( () => sections.value.filter( ( s ) => ! s.repeat ) );
const repeating = computed( () => sections.value.filter( ( s ) => s.repeat ) );
const stamp = ( s ) => ( s ? s.replace( 'T', ' ' ).slice( 0, 16 ) : '' );

onMounted( load );
</script>

<template>
	<div>
		<PageHeader title="Forms" description="Share a form's link, then read and export what people send. Answers are saved as they type, so you also see forms still in progress." />

		<el-card v-if="! current" shadow="never" v-loading="loading">
			<el-empty v-if="! loading && ! forms.length" description="No forms yet." />
			<el-table v-else :data="forms" @row-click="open" style="cursor:pointer">
				<el-table-column label="Form" min-width="220"><template #default="{ row }"><b>{{ row.title }}</b><div class="bd-muted">{{ row.url }}</div></template></el-table-column>
				<el-table-column label="Client" width="150"><template #default="{ row }">{{ row.client_name || '—' }}</template></el-table-column>
				<el-table-column label="Submitted" width="110"><template #default="{ row }">{{ row.counts.submitted }}</template></el-table-column>
				<el-table-column label="In progress" width="120"><template #default="{ row }">{{ row.counts.draft }}</template></el-table-column>
				<el-table-column label="Status" width="110"><template #default="{ row }"><el-tag :type="row.status === 'open' ? 'success' : 'info'" size="small">{{ row.status === 'open' ? 'Open' : 'Closed' }}</el-tag></template></el-table-column>
			</el-table>
		</el-card>

		<template v-else>
			<el-button text @click="current = null; load()">← All forms</el-button>
			<el-card shadow="never" style="margin-bottom:16px">
				<template #header><div class="bd-widget__head"><span>{{ current.title }}</span></div></template>
				<el-form label-position="top" @submit.prevent>
					<el-form-item label="Link to share">
						<el-input :model-value="current.url" readonly><template #append><el-button @click="copy( current.url )">Copy</el-button></template></el-input>
					</el-form-item>
					<el-form-item label="Client this form is for">
						<el-select :model-value="current.client_id || null" filterable remote clearable :remote-method="findClients" :loading="clientBusy" placeholder="Choose a client" style="width:100%" @change="( v ) => saveForm( { client_id: v || 0 } )">
							<el-option v-if="current.client_id && ! clients.some( ( c ) => c.id === current.client_id )" :value="current.client_id" :label="current.client_name" />
							<el-option v-for="c in clients" :key="c.id" :value="c.id" :label="c.name" />
						</el-select>
					</el-form-item>
					<el-form-item label="Taking answers">
						<el-switch :model-value="current.status === 'open'" active-text="Open" inactive-text="Closed" @change="( v ) => saveForm( { status: v ? 'open' : 'closed' } )" />
					</el-form-item>
					<el-form-item v-if="SenderPicker" label=""><SenderPicker v-model="senderEmail" label="Send this form's emails from" help="Applies to the copy sent to the person and the note to your team. Pick your own address to send as yourself; it is offered because it is on the same domain as the email settings." @update:model-value="( v ) => saveForm( { sender_email: v } )" /></el-form-item>
					<el-form-item label="Tell these emails when someone submits (separate with commas; empty uses the site admin email)">
						<el-input v-model="notify" placeholder="you@example.com" @keyup.enter="saveForm( { notify_email: notify } )"><template #append><el-button :loading="saving" @click="saveForm( { notify_email: notify } )">Save</el-button></template></el-input>
					</el-form-item>
				</el-form>
			</el-card>

			<el-card shadow="never" v-loading="subsLoading">
				<template #header><div class="bd-widget__head"><span>Answers</span><span><el-button size="small" @click="addDemo">Add sample answers</el-button> <el-button size="small" @click="exportCsv">Export CSV (one row per company)</el-button></span></div></template>
				<el-empty v-if="! subsLoading && ! subs.length" description="No answers yet. Share the link above." />
				<el-table v-else :data="subs" @row-click="show" style="cursor:pointer">
					<el-table-column label="From" min-width="180"><template #default="{ row }"><b>{{ row.name || '(no name yet)' }}</b><div class="bd-muted">{{ row.email }}</div></template></el-table-column>
					<el-table-column prop="org" label="Company" min-width="140" />
					<el-table-column label="Companies" width="110"><template #default="{ row }">{{ row.entities }}</template></el-table-column>
					<el-table-column label="Status" width="120"><template #default="{ row }"><el-tag :type="row.status === 'submitted' ? 'success' : 'warning'" size="small">{{ row.status === 'submitted' ? 'Submitted' : 'In progress' }}</el-tag></template></el-table-column>
					<el-table-column label="Resume link" width="130"><template #default="{ row }"><el-button size="small" @click.stop="copy( row.resume_url )">Copy link</el-button></template></el-table-column>
					<el-table-column label="Last change" width="150"><template #default="{ row }">{{ stamp( row.submitted_at || row.updated_at ) }}</template></el-table-column>
				</el-table>
			</el-card>
		</template>

		<el-drawer v-model="detailOpen" size="min(760px, 100%)" :title="detail ? detail.title : ''" destroy-on-close>
			<template v-if="detail">
				<p class="bd-muted">{{ detail.submission.name }} · {{ detail.submission.email }} · {{ detail.submission.status === 'submitted' ? 'Submitted ' + stamp( detail.submission.submitted_at ) : 'In progress' }}<template v-if="detail.submission.consent_at"> · Agreed to the consent on {{ stamp( detail.submission.consent_at ) }}</template></p>
				<el-input :model-value="detail.resume_url" readonly size="small" style="margin-bottom:10px"><template #prepend>Resume link</template><template #append><el-button @click="copy( detail.resume_url )">Copy</el-button></template></el-input>
				<div style="display:flex;gap:8px;margin-bottom:12px">
					<el-button size="small" type="primary" @click="openPdf">View PDF</el-button>
					<el-button size="small" @click="copy( detail.markdown )">Copy all as text</el-button>
					<el-button size="small" @click="downloadMd">Download .md</el-button>
					<el-button size="small" type="danger" plain @click="remove( detail.submission )">Delete</el-button>
				</div>
				<div v-for="s in general" :key="s.id" style="margin-bottom:18px">
					<h3 style="margin:0 0 8px">{{ s.title }}</h3>
					<template v-for="( f, i ) in s.fields" :key="i">
						<h4 v-if="f.type === 'heading'" style="margin:14px 0 4px">{{ f.label }}</h4>
						<div v-else-if="f.type === 'companies'" style="margin:8px 0">
							<b>{{ f.label }}</b>
							<el-table :data="data.general[ f.id ] || []" size="small" style="margin-top:6px">
								<el-table-column v-for="c in f.columns" :key="c.id" :prop="c.id" :label="c.label" min-width="140" />
							</el-table>
						</div>
						<div v-else-if="f.type === 'files'" style="margin:8px 0"><b>{{ f.label }}</b>
							<div v-for="fl in filesFor( 0, f.id )" :key="fl.id"><a :href="fileUrl( fl.id )" target="_blank" rel="noopener">{{ fl.name }}</a> <span class="bd-muted">{{ sizeText( fl.size ) }}</span></div>
							<div v-if="! filesFor( 0, f.id ).length">—</div></div>
						<div v-else-if="f.id" style="margin:8px 0"><b>{{ f.n ? f.n + '. ' : '' }}{{ f.label }}</b><div style="white-space:pre-wrap">{{ show_value( data.general[ f.id ] ) || '—' }}</div></div>
					</template>
				</div>
				<el-tabs v-if="repeating.length" v-model="tab" type="card">
					<el-tab-pane v-for="i in tabs" :key="i" :label="label( i )" :name="String( i )">
						<div v-for="s in repeating" :key="s.id" style="margin-bottom:18px">
							<h3 style="margin:0 0 8px">{{ s.title }}</h3>
							<template v-for="( f, k ) in s.fields" :key="k">
								<h4 v-if="f.type === 'heading'" style="margin:14px 0 4px">{{ f.label }}</h4>
								<div v-else-if="f.type === 'files'" style="margin:8px 0"><b>{{ f.label }}</b>
									<div v-for="fl in filesFor( i, f.id )" :key="fl.id"><a :href="fileUrl( fl.id )" target="_blank" rel="noopener">{{ fl.name }}</a> <span class="bd-muted">{{ sizeText( fl.size ) }}</span></div>
									<div v-if="! filesFor( i, f.id ).length">—</div></div>
								<div v-else-if="f.id" style="margin:8px 0"><b>{{ f.n ? f.n + '. ' : '' }}{{ f.label }}</b><div style="white-space:pre-wrap">{{ show_value( ( data.entities[ i ] || {} )[ f.id ] ) || '—' }}</div></div>
							</template>
						</div>
					</el-tab-pane>
				</el-tabs>
			</template>
		</el-drawer>
	</div>
</template>
