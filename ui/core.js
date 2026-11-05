// WPFlat Forms' app screens run inside WPFlat Core's page: everything they share comes from window.WPFlatCore.
const bc = window.WPFlatCore;
export const { sdk, api, errorMessage, store, navigate, util, ui, components } = bc;
export const { ElMessage } = ui;
export const { PageHeader, StatCard, SenderPicker } = components;
