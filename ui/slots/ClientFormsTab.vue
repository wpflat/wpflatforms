<script setup>
import { onMounted, ref, watch } from 'vue';
import { api, errorMessage, ElMessage } from '../core';

/** On a client's page: the forms made for them, each person's progress, and the link to resume. */
const props = defineProps( { clientId: { type: Number, required: true }, tick: { type: Number, default: 0 } } );
const rows = ref( [] );
const loading = ref( true );
const stamp = ( s ) => ( s ? s.replace( 'T', ' ' ).slice( 0, 16 ) : '' );
const copy = async ( t ) => {
	try {
		await navigator.clipboard.writeText( t );
		ElMessage.success( 'Copied' );
	} catch ( e ) {
		window.prompt( 'Copy this:', t );
	}
};
const load = async () => {
	loading.value = true;
	try {
		const forms = await api.getPath( 'forms', { client_id: props.clientId } );
		rows.value = await Promise.all( forms.map( async ( f ) => ( { ...f, subs: await api.getPath( `forms/${ f.id }/submissions` ) } ) ) );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ props.clientId, props.tick ], load );
</script>

<template>
	<div v-loading="loading">
		<h3 class="bd-subhead" style="margin-top: 4px">Forms</h3>
		<el-empty v-if="! loading && ! rows.length" description="No forms are attached to this client yet. Attach one in Forms." />
		<el-card v-for="f in rows" :key="f.id" shadow="never" style="margin-bottom:12px">
			<template #header><div class="bd-widget__head"><span>{{ f.title }}</span><el-button size="small" @click="copy( f.url )">Copy form link</el-button></div></template>
			<el-empty v-if="! f.subs.length" description="Nobody has started this form yet." :image-size="50" />
			<el-table v-else :data="f.subs" size="small">
				<el-table-column label="From" min-width="150"><template #default="{ row }">{{ row.name || '(no name yet)' }}<div class="bd-muted">{{ row.email }}</div></template></el-table-column>
				<el-table-column label="Status" width="110"><template #default="{ row }"><el-tag :type="row.status === 'submitted' ? 'success' : 'warning'" size="small">{{ row.status === 'submitted' ? 'Submitted' : 'In progress' }}</el-tag></template></el-table-column>
				<el-table-column label="Last change" width="140"><template #default="{ row }">{{ stamp( row.submitted_at || row.updated_at ) }}</template></el-table-column>
				<el-table-column label="Resume link" width="120"><template #default="{ row }"><el-button size="small" @click="copy( row.resume_url )">Copy link</el-button></template></el-table-column>
			</el-table>
		</el-card>
	</div>
</template>
