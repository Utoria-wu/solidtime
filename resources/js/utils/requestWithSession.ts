import axios, { type AxiosRequestConfig } from 'axios';
import { fetchToken } from '@/utils/session';

export async function requestWithSession<T>(config: AxiosRequestConfig): Promise<T> {
    try {
        return (await axios.request<T>(config)).data;
    } catch (error) {
        if (!axios.isAxiosError(error) || error.response?.status !== 401) throw error;
        await fetchToken();
        return (await axios.request<T>(config)).data;
    }
}
